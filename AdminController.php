<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function dashboard()
    {
        // Thống kê
        $tongSanPham = DB::table('SanPham')->count();
        $tongKhachHang = DB::table('KhachHang')->count();
        $tongDonHang = DB::table('DonHang')->count();

        $doanhThu = DB::table('DonHang')
            ->where('TrangThai', 'Hoàn thành')
            ->sum('TongTien');

        // Biểu đồ
        $chartData = DB::table('DonHang')
            ->select(
                DB::raw('MONTH(created_at) as thang'),
                DB::raw('SUM(TongTien) as tong')
            )
            ->where('TrangThai', 'Hoàn thành')
            ->groupBy('thang')
            ->orderBy('thang')
            ->get();

        $labels = [];
        $data = [];

        foreach ($chartData as $item) {
            $labels[] = 'Tháng ' . $item->thang;
            $data[] = $item->tong;
        }

        // Đơn hàng mới
        $donHangMoi = DB::table('DonHang')
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get();

        return view('admin.dashboard', compact(
            'tongSanPham',
            'tongKhachHang',
            'tongDonHang',
            'doanhThu',
            'labels',
            'data',
            'donHangMoi'
        ));
    }
}