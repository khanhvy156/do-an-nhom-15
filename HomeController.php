<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash; // Thêm dòng này để dùng hàm Hash
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function index()
    {
        $products = DB::table('sanpham')->get();
        return view('home', compact('products'));
    }

    // HÀM NÀY ĐỂ BẠN TEST: Copy vào và chạy thử đường dẫn /test-login
    public function testLogin()
    {
        $tenDangNhap = 'admin'; // Thay bằng tên đăng nhập của bạn trong DB
        $matKhauNhapVao = '123456'; // Mật khẩu bạn nghĩ là đúng

        $user = DB::table('khachhang')->where('TenDangNhap', $tenDangNhap)->first();

        if (!$user) {
            return "Lỗi: Không tìm thấy người dùng này trong bảng khachhang!";
        }

        // Kiểm tra xem mật khẩu trong DB có đúng chuẩn Hash không
        $check = Hash::check($matKhauNhapVao, $user->password);

        return [
            'Tên đăng nhập' => $tenDangNhap,
            'Mật khẩu trong DB (đã mã hóa)' => $user->password,
            'Kết quả kiểm tra' => $check ? 'MẬT KHẨU KHỚP - ĐĂNG NHẬP ĐƯỢC' : 'SAI MẬT KHẨU - HASH KHÔNG KHỚP'
        ];
    }
}