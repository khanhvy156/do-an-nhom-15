<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\DB;

class GioHangController extends Controller
{
    // =========================
    // HIỂN THỊ GIỎ HÀNG
    // =========================

    public function index()
    {
        // Bắt buộc login
        if (!session()->has('khachhang')) {
            return redirect('/login');
        }

        $cartItems = Session::get('cart', []);

        $total = 0;

        foreach ($cartItems as $item) {
            $total += $item['Gia'] * $item['SoLuong'];
        }

        return view('cart.index', compact('cartItems', 'total'));
    }

    // =========================
    // THÊM VÀO GIỎ HÀNG
    // =========================

    public function addToCart(Request $request, $id)
    {
        // Bắt buộc login
        if (!session()->has('khachhang')) {

            return redirect('/login')
                ->with('error', 'Vui lòng đăng nhập trước khi đặt hàng!');
        }

        // Tìm sản phẩm
        $product = DB::table('sanpham')
            ->where('MaSanPham', $id)
            ->first();

        if (!$product) {
            return back()->with('error', 'Sản phẩm không tồn tại!');
        }

        $cart = Session::get('cart', []);

        // Giá hiện tại
        $gia_hien_tai = ($product->gia_giam > 0)
            ? $product->gia_giam
            : $product->Gia;

        // Nếu đã có
        if (isset($cart[$id])) {

            $cart[$id]['SoLuong']++;

        } else {

            $cart[$id] = [

                "TenSanPham" => $product->TenSanPham,

                "SoLuong" => 1,

                "Gia" => $gia_hien_tai,

                "GiaGoc" => $product->Gia,

                "hinhanh" => $product->hinhanh

            ];
        }

        Session::put('cart', $cart);

        return redirect()
            ->route('cart.index')
            ->with('success', 'Đã thêm vào giỏ hàng!');
    }

    // =========================
    // CẬP NHẬT SỐ LƯỢNG
    // =========================

    public function update(Request $request, $id)
    {
        $cart = Session::get('cart');

        if (isset($cart[$id])) {

            $product = DB::table('sanpham')
                ->where('MaSanPham', $id)
                ->first();

            if ($request->quantity > $product->so_luong_con) {

                return back()
                    ->with('error', 'Vượt quá hàng tồn kho!');
            }

            $cart[$id]['SoLuong'] = $request->quantity;

            Session::put('cart', $cart);
        }

        return back()
            ->with('success', 'Cập nhật thành công!');
    }

    // =========================
    // XÓA SẢN PHẨM
    // =========================

    public function remove($id)
    {
        $cart = Session::get('cart');

        if (isset($cart[$id])) {

            unset($cart[$id]);

            Session::put('cart', $cart);
        }

        return back()
            ->with('success', 'Đã xóa khỏi giỏ hàng!');
    }
}