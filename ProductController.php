public function getMenu(Request $request)
{
    $danhmuc_id = $request->madanhmuc ?? 0;
    $danhmuc = DB::table('danhmuc')->get();
    
    $query = DB::table('sanpham');
    if ($danhmuc_id != 0) {
        $query->where('MaDanhMuc', $danhmuc_id);
        $danhmuc_ten = DB::table('danhmuc')->where('MaDanhMuc', $danhmuc_id)->value('TenDanhMuc');
    } else {
        $danhmuc_ten = "TẤT CẢ SẢN PHẨM";
    }
    
    $products = $query->get();

    return view('menu', compact('products', 'danhmuc', 'danhmuc_id', 'danhmuc_ten'));
}