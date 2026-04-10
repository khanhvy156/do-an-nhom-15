<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        // Lấy dữ liệu và loại bỏ khoảng trắng thừa
        $credentials = [
            'HoVaTen'  => trim($request->username), 
            'password' => $request->password,
        ];

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();
            return response()->json([
                'success' => true,
                'message' => 'Đăng nhập thành công!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Tên hoặc mật khẩu không chính xác!'
        ]);
    }
}