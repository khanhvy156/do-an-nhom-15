<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class CartController extends Controller
{
    /*
    =========================================
    THÊM SẢN PHẨM VÀO GIỎ HÀNG (AJAX)
    =========================================
    */

    public function addToCart(Request $request)
    {
        // 1. Kiểm tra đăng nhập
        if (!session()->has('khachhang')) {

            return response()->json([
                'success' => false,
                'message' => 'Vui lòng đăng nhập!',
                'redirect' => '/login'
            ], 401);
        }

        try {

            // 2. Lấy ID sản phẩm từ request
            $productId = $request->input('id') ?? $request->input('MaSanPham') ?? $request->input('maSP');

            if (!$productId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy sản phẩm!'
                ]);
            }

            // 3. Lấy thông tin sản phẩm
            $product = DB::table('sanpham')
                ->where('MaSanPham', $productId)
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sản phẩm không tồn tại!'
                ]);
            }

            // 4. Lấy giỏ hàng từ session
            $cart = Session::get('cart', []);

            // Giá hiện tại
            $giaGoc = (float) $product->Gia;
            $gia_hien_tai = ($product->gia_giam > 0)
                ? (float) $product->gia_giam
                : (($product->khuyenmai ?? 0) == 1 ? round($giaGoc * 0.9) : $giaGoc);

            // 5. Nếu sản phẩm đã có trong giỏ
            if (isset($cart[$productId])) {

                $cart[$productId]['SoLuong']++;

            } else {

                $cart[$productId] = [

                    "TenSanPham" => $product->TenSanPham,

                    "SoLuong" => 1,

                    "Gia" => $gia_hien_tai,

                    "GiaGoc" => $giaGoc,

                    "hinhanh" => $product->hinhanh

                ];
            }

            // 6. Lưu lại session
            Session::put('cart', $cart);

            // 7. Đếm tổng số lượng
            $totalCount = 0;

            foreach ($cart as $item) {
                $totalCount += $item['SoLuong'];
            }

            return response()->json([
                'success' => true,
                'message' => 'Đã thêm vào giỏ hàng!',
                'count' => $totalCount
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Lỗi: ' . $e->getMessage()
            ], 500);
        }
    }

    /*
    =========================================
    HIỂN THỊ GIỎ HÀNG
    =========================================
    */

    public function viewCart()
    {
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

    /*
    =========================================
    CẬP NHẬT SỐ LƯỢNG
    =========================================
    */

    public function update(Request $request, $id)
    {
        $cart = Session::get('cart', []);

        if (isset($cart[$id])) {
            $newQty = (int) $request->quantity;

            // Nếu giảm về 0 (hoặc nhỏ hơn) thì xóa sản phẩm khỏi giỏ
            if ($newQty <= 0) {
                unset($cart[$id]);
                Session::put('cart', $cart);
                return back()->with('success', 'Đã xóa sản phẩm khỏi giỏ hàng!');
            }

            $product = DB::table('sanpham')
                ->where('MaSanPham', $id)
                ->first();

            if ($newQty > $product->so_luong_con) {

                return back()->with(
                    'error',
                    'Vượt quá hàng tồn kho!'
                );
            }

            $cart[$id]['SoLuong'] = $newQty;

            Session::put('cart', $cart);
        }

        return back()->with(
            'success',
            'Cập nhật thành công!'
        );
    }

    /*
    =========================================
    XÓA SẢN PHẨM
    =========================================
    */

    public function deleteItem($id)
    {
        $cart = Session::get('cart', []);

        if (isset($cart[$id])) {

            unset($cart[$id]);

            Session::put('cart', $cart);
        }

        return back()->with(
            'success',
            'Đã xóa sản phẩm!'
        );
    }
}