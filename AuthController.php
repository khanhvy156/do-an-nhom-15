<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /*
    =========================================
    HIỂN THỊ TRANG LOGIN
    =========================================
    */
    public function showLogin()
    {
        return view('auth.login');
    }

    /*
    =========================================
    ĐĂNG NHẬP (AJAX)
    =========================================
    */
    public function login(Request $request)
    {
        try {

            // Lấy dữ liệu từ form
            $hoten = trim($request->HoVaTen);
            $password = trim($request->password);

            // Kiểm tra rỗng
            if (!$hoten || !$password) {

                return response()->json([
                    'success' => false,
                    'message' => 'Vui lòng nhập đầy đủ thông tin!'
                ]);
            }

            // Tìm tài khoản trong database
            $khachhang = DB::table('khachhang')
                ->where('HoVaTen', $hoten)
                ->first();

            // Không tồn tại tài khoản
            if (!$khachhang) {

                return response()->json([
                    'success' => false,
                    'message' => 'Tài khoản không tồn tại!'
                ]);
            }

            // So sánh mật khẩu (dạng text)
            if ($password != $khachhang->password) {

                return response()->json([
                    'success' => false,
                    'message' => 'Tài khoản hoặc mật khẩu không chính xác!'
                ]);
            }

            // Lưu session đăng nhập
            session([
                'khachhang' => $khachhang,
                'khachhang_id' => $khachhang->MaKhachHang,
                'khachhang_ten' => $khachhang->HoVaTen
            ]);

            // Nếu người dùng submit form bình thường (không phải AJAX),
            // thì redirect để tránh hiện JSON trên trình duyệt.
            if (!$request->expectsJson() && !$request->wantsJson()) {
                return redirect('/')->with('success', 'Đăng nhập thành công!');
            }

            return response()->json([
                'success' => true,
                'message' => 'Đăng nhập thành công!'
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Lỗi server!',
                'error' => $e->getMessage()
            ]);
        }
    }

    /*
    =========================================
    ĐĂNG KÝ (AJAX)
    =========================================
    */
    public function register(Request $request)
    {
        try {
            $hoten = trim((string) $request->HoVaTen);
            $email = trim((string) $request->Email);
            $sdt = trim((string) $request->SoDienThoai);
            $password = trim((string) $request->password);

            if (!$hoten || !$email || !$sdt || !$password) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vui lòng nhập đầy đủ thông tin!'
                ], 422);
            }

            $exists = DB::table('khachhang')
                ->where('Email', $email)
                ->orWhere('SoDienThoai', $sdt)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email hoặc số điện thoại đã tồn tại!'
                ], 409);
            }

            $id = DB::table('khachhang')->insertGetId([
                'HoVaTen' => $hoten,
                'Email' => $email,
                'SoDienThoai' => $sdt,
                'password' => $password,
            ]);

            $khachhang = DB::table('khachhang')->where('MaKhachHang', $id)->first();

            session([
                'khachhang' => $khachhang,
                'khachhang_id' => $khachhang->MaKhachHang,
                'khachhang_ten' => $khachhang->HoVaTen
            ]);

            if (!$request->expectsJson() && !$request->wantsJson()) {
                return redirect('/')->with('success', 'Đăng ký thành công!');
            }

            return response()->json([
                'success' => true,
                'message' => 'Đăng ký thành công!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi server!',
            ], 500);
        }
    }

    /*
    =========================================
    ĐĂNG XUẤT
    =========================================
    */
    public function logout(Request $request)
    {
        // Xóa toàn bộ state liên quan khách hàng + giỏ hàng
        $request->session()->forget([
            'khachhang',
            'khachhang_id',
            'khachhang_ten',
            'cart',
        ]);

        // Đổi session id/token để tránh giữ state cũ
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

}