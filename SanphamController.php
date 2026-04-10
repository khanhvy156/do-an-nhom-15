<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sanpham; // Đảm bảo bạn đã có file Model Sanpham.php

class SanphamController extends Controller
{
    /**
     * Hiển thị chi tiết sản phẩm
     * Hỗ trợ cả Load trang truyền thống và AJAX
     */
    public function show($id, Request $request)
    {
        // 1. Lấy dữ liệu sản phẩm từ Database theo ID
        // Nếu không tìm thấy sẽ tự động trả về lỗi 404
        $sanpham = sanpham::findOrFail($id);

        // 2. Kiểm tra xem đây có phải là yêu cầu AJAX không
        // (Khi bạn dùng hàm fetch() trong Javascript, Laravel sẽ nhận diện được)
        if ($request->ajax()) {
            // Nếu là AJAX, chỉ trả về phần nội dung chi tiết (không có header/footer)
            // Để nó dán đè vào cái thẻ <div id="main-content"> của bạn
            return view('sanpham.chitiet', compact('sanpham'));
        }

        // 3. Nếu người dùng nhập trực tiếp link lên trình duyệt (không qua AJAX)
        // Bạn có thể trả về trang chi tiết nằm trong layout tổng
        return view('sanpham.chitiet', compact('sanpham'));
    }

    /**
     * Hàm bổ trợ: Lấy danh sách sản phẩm cho trang Menu (nếu bạn cần)
     */
    public function index(Request $request)
    {
        $ds_sanpham = sanpham::all();

        if ($request->ajax()) {
            return view('menu_content', compact('ds_sanpham'));
        }

        return view('menu', compact('ds_sanpham'));
    }
}