<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;

class CheckoutController extends Controller
{
    public function index()
    {
        if (!session()->has('khachhang')) {
            return redirect('/login');
        }

        $cartItems = Session::get('cart', []);
        if (empty($cartItems)) {
            return redirect('/cart')->with('error', 'Giỏ hàng trống!');
        }

        $total = 0;
        foreach ($cartItems as $item) {
            $total += ((float) ($item['Gia'] ?? 0)) * ((int) ($item['SoLuong'] ?? 0));
        }

        // Trả về giao diện cũ (Tailwind) ở resources/views/checkout/index.blade.php
        $cart = $cartItems;
        return view('checkout.index', compact('cart', 'cartItems', 'total'));
    }

    public function success()
    {
        $data = session()->pull('checkout_success');
        if (!$data) {
            return redirect('/');
        }

        return view('checkout.success', ['data' => $data]);
    }

    public function placeOrder(Request $request)
    {
        if (!session()->has('khachhang')) {
            return redirect('/login');
        }

        $cartItems = Session::get('cart', []);
        if (empty($cartItems)) {
            return redirect('/cart')->with('error', 'Giỏ hàng trống!');
        }

        $request->validate([
            'txt_hoten' => ['required', 'string', 'max:255'],
            'txt_sdt' => ['required', 'string', 'max:30'],
            'txt_email' => ['required', 'email', 'max:255'],
            'txt_diachi' => ['required', 'string', 'max:1000'],
            'payment_method' => ['required', 'string'],
        ]);

        $paymentMethodLabel = match ($request->payment_method) {
            'cod' => 'Tiền mặt',
            'bank' => 'Chuyển khoản',
            default => (string) $request->payment_method,
        };

        $total = 0;
        foreach ($cartItems as $item) {
            $total += ((float) ($item['Gia'] ?? 0)) * ((int) ($item['SoLuong'] ?? 0));
        }

        $maKhachHang = session('khachhang_id');

        // Lưu đơn hàng (chỉ insert các cột thật sự tồn tại trong DB để tránh lỗi)
        $donhangData = [
            'MaKhachHang' => $maKhachHang,
            'TongTien' => $total,
            'TrangThai' => 'Đặt hàng thành công',
            'created_at' => now(),
            'updated_at' => now(),
            // Thông tin giao hàng (nếu bảng có cột thì sẽ được insert)
            'HoVaTen' => $request->txt_hoten,
            'SoDienThoai' => $request->txt_sdt,
            'Email' => $request->txt_email,
            'DiaChi' => $request->txt_diachi,
            'HinhThucThanhToan' => $paymentMethodLabel,
            'PhuongThucTT' => $paymentMethodLabel,
        ];

        $donhangColumns = [];
        try {
            if (Schema::hasTable('donhang')) {
                $donhangColumns = Schema::getColumnListing('donhang');
            }
        } catch (\Throwable $e) {
            $donhangColumns = [];
        }

        if (!empty($donhangColumns)) {
            $donhangData = array_intersect_key($donhangData, array_flip($donhangColumns));
        }

        $maDonHang = DB::table('donhang')->insertGetId($donhangData);

        // Nếu có bảng chi tiết đơn hàng thì lưu (bỏ qua nếu không tồn tại)
        try {
            if (!Schema::hasTable('chitietdonhang')) {
                throw new \RuntimeException('missing table');
            }

            $ctColumns = Schema::getColumnListing('chitietdonhang');
            foreach ($cartItems as $maSanPham => $item) {
                $ctData = [
                    'MaDonHang' => $maDonHang,
                    'MaSanPham' => $maSanPham,
                    'SoLuong' => (int) ($item['SoLuong'] ?? 0),
                    'DonGia' => (float) ($item['Gia'] ?? 0),
                    'GiaGoc' => (float) ($item['GiaGoc'] ?? $item['Gia'] ?? 0),
                    'TenSanPham' => $item['TenSanPham'] ?? null,
                    'HinhAnh' => $item['hinhanh'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (!empty($ctColumns)) {
                    $ctData = array_intersect_key($ctData, array_flip($ctColumns));
                }

                DB::table('chitietdonhang')->insert($ctData);
            }
        } catch (\Throwable $e) {
            // Không chặn luồng nếu DB hiện không có bảng này
        }

        // Lưu dữ liệu để hiển thị trang cảm ơn
        $successData = [
            'order_id' => $maDonHang,
            'total' => $total,
            'customer' => [
                'hoten' => $request->txt_hoten,
                'sdt' => $request->txt_sdt,
                'email' => $request->txt_email,
                'diachi' => $request->txt_diachi,
                'payment_method' => $paymentMethodLabel,
            ],
            'items' => array_values($cartItems),
        ];

        Session::forget('cart');
        session(['checkout_success' => $successData]);

        return redirect()->route('checkout.success');
    }
}