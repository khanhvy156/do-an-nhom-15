<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\MenuController;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| 1. FRONTEND
|--------------------------------------------------------------------------
*/
Route::get('/', function () {

    $sanpham = DB::table('sanpham')
        ->orderBy('MaSanPham', 'desc')
        ->limit(8)
        ->get();

    return view('home', compact('sanpham'));
});



// Trang tĩnh
Route::view('/gioi-thieu', 'gioithieu');
Route::view('/dich-vu', 'dichvu');
Route::view('/he-thong', 'hethong');
Route::view('/lien-he', 'contact');

/*
|--------------------------------------------------------------------------
| CHAT (KHÁCH + ADMIN)
|--------------------------------------------------------------------------
*/

// Khách mở chat: tạo/khôi phục chat_session_id trong session
Route::post('/chat/start', function (Request $request) {
    $khId = session('khachhang_id');

    $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'phone' => ['required', 'string', 'max:30'],
    ]);

    if (!Schema::hasTable('chat_sessions')) {
        return response()->json(['success' => false, 'message' => 'Chat chưa sẵn sàng (thiếu bảng).'], 500);
    }

    $chatSessionId = (int) session('chat_session_id', 0);
    if ($chatSessionId > 0) {
        $exists = DB::table('chat_sessions')->where('id', $chatSessionId)->exists();
        if ($exists) {
            return response()->json(['success' => true, 'session_id' => $chatSessionId]);
        }
    }

    // client token (để resume dù mất cookie session)
    $clientToken = (string) $request->header('X-CHAT-TOKEN', '');
    if ($clientToken === '') {
        $clientToken = session('guest_chat_token') ?: '';
    }
    if ($clientToken === '') {
        $clientToken = bin2hex(random_bytes(16));
    }
    session(['guest_chat_token' => $clientToken]);

    // Nếu đã có session open theo khachhang_id/token thì reuse
    $q = DB::table('chat_sessions')->where('status', 'open');
    if ($khId) {
        $q->where('khachhang_id', $khId);
    } else {
        $q->where('guest_token', $clientToken);
    }

    $existing = $q->orderByDesc('id')->first();
    if ($existing) {
        // Cập nhật lại thông tin liên hệ (nếu khách nhập mới)
        DB::table('chat_sessions')->where('id', (int) $existing->id)->update([
            'khachhang_id' => $khId ?: $existing->khachhang_id,
            'customer_name' => trim((string) $request->name),
            'customer_phone' => trim((string) $request->phone),
            'updated_at' => now(),
        ]);

        session(['chat_session_id' => $existing->id]);

        return response()->json([
            'success' => true,
            'session_id' => (int) $existing->id,
            'chat_token' => $clientToken,
        ]);
    }

    $name = trim((string) $request->input('name'));
    $phone = trim((string) $request->input('phone'));

    $id = DB::table('chat_sessions')->insertGetId([
        'khachhang_id' => $khId ?: null,
        'guest_token' => $clientToken,
        'customer_name' => $name,
        'customer_phone' => $phone,
        'status' => 'open',
        'last_message_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    session(['chat_session_id' => $id]);

    return response()->json([
        'success' => true,
        'session_id' => (int) $id,
        'chat_token' => $clientToken,
    ]);
});

// Khách gửi tin
Route::post('/chat/send', function (Request $request) {
    if (!Schema::hasTable('chat_messages') || !Schema::hasTable('chat_sessions')) {
        return response()->json(['success' => false, 'message' => 'Chat chưa sẵn sàng.'], 500);
    }

    $request->validate([
        'message' => ['required', 'string', 'max:2000'],
    ]);

    // Ưu tiên resolve theo token (ổn định hơn session cookie)
    $clientToken = (string) $request->header('X-CHAT-TOKEN', '');
    $chatSessionId = 0;
    if ($clientToken !== '' && Schema::hasTable('chat_sessions')) {
        $s = DB::table('chat_sessions')->where('guest_token', $clientToken)->where('status', 'open')->first();
        if ($s) {
            $chatSessionId = (int) $s->id;
            session(['chat_session_id' => $chatSessionId, 'guest_chat_token' => $clientToken]);
        }
    }
    if ($chatSessionId <= 0) {
        $chatSessionId = (int) session('chat_session_id', 0);
    }
    if ($chatSessionId <= 0) {
        return response()->json(['success' => false, 'message' => 'Chưa có phiên chat.'], 422);
    }

    $sessionRow = DB::table('chat_sessions')->where('id', $chatSessionId)->first();
    if (!$sessionRow) {
        return response()->json(['success' => false, 'message' => 'Phiên chat không tồn tại.'], 404);
    }

    $senderName = session('khachhang_ten') ?: ($sessionRow->customer_name ?: 'Khách hàng');
    $text = trim((string) $request->message);

    // Chặn gửi trùng (double click / double submit)
    $last = DB::table('chat_messages')
        ->where('chat_session_id', $chatSessionId)
        ->where('sender_type', 'customer')
        ->orderByDesc('id')
        ->first();

    if ($last && isset($last->message) && $last->message === $text) {
        // nếu 2 lần gửi sát nhau (<= 2s) thì coi là trùng
        $lastAt = $last->created_at ? strtotime($last->created_at) : null;
        if ($lastAt && (time() - $lastAt) <= 2) {
            return response()->json(['success' => true, 'message_id' => (int) $last->id]);
        }
    }

    $msgId = DB::table('chat_messages')->insertGetId([
        'chat_session_id' => $chatSessionId,
        'sender_type' => 'customer',
        'sender_name' => $senderName,
        'message' => $text,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('chat_sessions')->where('id', $chatSessionId)->update([
        'last_message_at' => now(),
        'updated_at' => now(),
    ]);

    // Auto-reply: chỉ gửi đúng 1 lần, ngay sau tin nhắn đầu tiên của khách
    $customerCount = DB::table('chat_messages')
        ->where('chat_session_id', $chatSessionId)
        ->where('sender_type', 'customer')
        ->count();
    $hasAdmin = DB::table('chat_messages')
        ->where('chat_session_id', $chatSessionId)
        ->where('sender_type', 'admin')
        ->exists();
    if ($customerCount === 1 && !$hasAdmin) {
        DB::table('chat_messages')->insert([
            'chat_session_id' => $chatSessionId,
            'sender_type' => 'admin',
            'sender_name' => 'FastFood',
            'message' => 'Xin chào bạn! FastFood đã nhận được tin nhắn. Bạn cần hỗ trợ gì ạ?',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('chat_sessions')->where('id', $chatSessionId)->update([
            'last_message_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return response()->json(['success' => true, 'message_id' => (int) $msgId]);
});

// Khách lấy tin nhắn mới
Route::get('/chat/fetch', function (Request $request) {
    if (!Schema::hasTable('chat_messages')) {
        return response()->json(['success' => false, 'message' => 'Chat chưa sẵn sàng.'], 500);
    }

    $clientToken = (string) $request->header('X-CHAT-TOKEN', '');
    $sid = (int) $request->query('sid', 0);

    // Ưu tiên dùng sid nếu client gửi lên (ổn định nhất). Nếu có token thì check token khớp session.
    $chatSessionId = 0;
    if ($sid > 0 && Schema::hasTable('chat_sessions')) {
        $s = DB::table('chat_sessions')->where('id', $sid)->first();
        if ($s && ($s->status ?? 'open') === 'open') {
            $ok = false;
            if ($clientToken !== '' && ($s->guest_token ?? '') === $clientToken) $ok = true;
            if (!$ok && (int) session('khachhang_id', 0) > 0 && (int) ($s->khachhang_id ?? 0) === (int) session('khachhang_id')) $ok = true;
            if (!$ok && (int) session('chat_session_id', 0) === (int) $sid) $ok = true;
            if ($ok) {
                $chatSessionId = (int) $sid;
                session(['chat_session_id' => $chatSessionId]);
                if ($clientToken !== '') session(['guest_chat_token' => $clientToken]);
            }
        }
    }

    // Fallback: resolve theo token / session
    if ($chatSessionId <= 0) {
        if ($clientToken !== '' && Schema::hasTable('chat_sessions')) {
            $s = DB::table('chat_sessions')->where('guest_token', $clientToken)->where('status', 'open')->first();
            if ($s) {
                $chatSessionId = (int) $s->id;
                session(['chat_session_id' => $chatSessionId, 'guest_chat_token' => $clientToken]);
            }
        }
    }
    if ($chatSessionId <= 0) $chatSessionId = (int) session('chat_session_id', 0);
    if ($chatSessionId <= 0) return response()->json(['success' => true, 'messages' => []]);

    $afterId = (int) $request->query('after_id', 0);
    $rows = DB::table('chat_messages')
        ->where('chat_session_id', $chatSessionId)
        ->where('id', '>', $afterId)
        ->orderBy('id', 'asc')
        ->limit(50)
        ->get();

    $messages = $rows->map(function ($m) {
        return [
            'id' => (int) $m->id,
            'sender_type' => $m->sender_type,
            'sender_name' => $m->sender_name,
            'message' => $m->message,
            'created_at_fmt' => $m->created_at ? date('H:i d/m', strtotime($m->created_at)) : '',
        ];
    });

    return response()->json(['success' => true, 'messages' => $messages]);
});

// Long-poll: khách chờ tin nhắn mới (gần realtime, không cần reload)
Route::get('/chat/poll', function (Request $request) {
    if (!Schema::hasTable('chat_messages')) {
        return response()->json(['success' => false, 'message' => 'Chat chưa sẵn sàng.'], 500);
    }

    // Reuse cùng logic resolve session như /chat/fetch
    $clientToken = (string) $request->header('X-CHAT-TOKEN', '');
    $sid = (int) $request->query('sid', 0);

    $chatSessionId = 0;
    if ($sid > 0 && Schema::hasTable('chat_sessions')) {
        $s = DB::table('chat_sessions')->where('id', $sid)->first();
        if ($s && ($s->status ?? 'open') === 'open') {
            $ok = false;
            if ($clientToken !== '' && ($s->guest_token ?? '') === $clientToken) $ok = true;
            if (!$ok && (int) session('khachhang_id', 0) > 0 && (int) ($s->khachhang_id ?? 0) === (int) session('khachhang_id')) $ok = true;
            if (!$ok && (int) session('chat_session_id', 0) === (int) $sid) $ok = true;
            if ($ok) {
                $chatSessionId = (int) $sid;
                session(['chat_session_id' => $chatSessionId]);
                if ($clientToken !== '') session(['guest_chat_token' => $clientToken]);
            }
        }
    }

    if ($chatSessionId <= 0) {
        if ($clientToken !== '' && Schema::hasTable('chat_sessions')) {
            $s = DB::table('chat_sessions')->where('guest_token', $clientToken)->where('status', 'open')->first();
            if ($s) {
                $chatSessionId = (int) $s->id;
                session(['chat_session_id' => $chatSessionId, 'guest_chat_token' => $clientToken]);
            }
        }
    }
    if ($chatSessionId <= 0) $chatSessionId = (int) session('chat_session_id', 0);
    if ($chatSessionId <= 0) return response()->json(['success' => true, 'messages' => []]);

    $afterId = (int) $request->query('after_id', 0);
    $deadlineMs = (int) $request->query('timeout_ms', 20000);
    if ($deadlineMs < 2000) $deadlineMs = 2000;
    if ($deadlineMs > 25000) $deadlineMs = 25000;

    $start = microtime(true);
    while (true) {
        $rows = DB::table('chat_messages')
            ->where('chat_session_id', $chatSessionId)
            ->where('id', '>', $afterId)
            ->orderBy('id', 'asc')
            ->limit(50)
            ->get();

        if ($rows->count() > 0) {
            $messages = $rows->map(function ($m) {
                return [
                    'id' => (int) $m->id,
                    'sender_type' => $m->sender_type,
                    'sender_name' => $m->sender_name,
                    'message' => $m->message,
                    'created_at_fmt' => $m->created_at ? date('H:i d/m', strtotime($m->created_at)) : '',
                ];
            });

            return response()->json(['success' => true, 'messages' => $messages]);
        }

        $elapsedMs = (int) ((microtime(true) - $start) * 1000);
        if ($elapsedMs >= $deadlineMs) {
            return response()->json(['success' => true, 'messages' => []]);
        }

        usleep(100000); // 100ms (nhanh hơn, cảm giác realtime)
    }
});

// SSE stream: đẩy tin nhắn mới cho khách (gần realtime, không cần F5)
Route::get('/chat/stream', function (Request $request) {
    if (!Schema::hasTable('chat_messages') || !Schema::hasTable('chat_sessions')) {
        return response('Chat chưa sẵn sàng.', 500);
    }

    // SSE không gửi custom headers ổn định, nên nhận token từ query/header
    $clientToken = (string) ($request->query('token') ?: $request->header('X-CHAT-TOKEN', ''));
    $sid = (int) $request->query('sid', 0);
    $afterId = (int) $request->query('after_id', 0);

    $chatSessionId = 0;
    if ($sid > 0) {
        $s = DB::table('chat_sessions')->where('id', $sid)->first();
        if ($s && ($s->status ?? 'open') === 'open') {
            if ($clientToken !== '' && ($s->guest_token ?? '') === $clientToken) {
                $chatSessionId = (int) $sid;
            } elseif ((int) session('khachhang_id', 0) > 0 && (int) ($s->khachhang_id ?? 0) === (int) session('khachhang_id')) {
                $chatSessionId = (int) $sid;
            }
        }
    }
    if ($chatSessionId <= 0 && $clientToken !== '') {
        $s = DB::table('chat_sessions')->where('guest_token', $clientToken)->where('status', 'open')->first();
        if ($s) $chatSessionId = (int) $s->id;
    }
    if ($chatSessionId <= 0) {
        return response('no-session', 200)->header('Content-Type', 'text/event-stream');
    }

    // Headers SSE
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    $start = microtime(true);
    $timeoutSec = 25;
    $lastSent = $afterId;

    // ping mở kết nối
    echo "event: ping\n";
    echo "data: {}\n\n";
    @ob_flush(); @flush();

    while (true) {
        // stop after timeout to let client reconnect (tránh giữ process quá lâu)
        if ((microtime(true) - $start) >= $timeoutSec) {
            echo "event: ping\n";
            echo "data: {}\n\n";
            @ob_flush(); @flush();
            break;
        }

        $rows = DB::table('chat_messages')
            ->where('chat_session_id', $chatSessionId)
            ->where('id', '>', $lastSent)
            ->orderBy('id', 'asc')
            ->limit(50)
            ->get();

        if ($rows->count() > 0) {
            $payload = $rows->map(function ($m) {
                return [
                    'id' => (int) $m->id,
                    'sender_type' => $m->sender_type,
                    'sender_name' => $m->sender_name,
                    'message' => $m->message,
                    'created_at_fmt' => $m->created_at ? date('H:i d/m', strtotime($m->created_at)) : '',
                ];
            })->values();

            $lastSent = (int) $rows->last()->id;
            echo "event: messages\n";
            echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            @ob_flush(); @flush();
        } else {
            // keep-alive
            echo "event: ping\n";
            echo "data: {}\n\n";
            @ob_flush(); @flush();
        }

        usleep(250000); // 250ms
    }
    return response()->noContent();
});

// Trạng thái chat hiện tại (để resume sau khi reload)
Route::get('/chat/status', function () {
    if (!Schema::hasTable('chat_sessions')) {
        return response()->json(['success' => false, 'message' => 'Chat chưa sẵn sàng.'], 500);
    }

    $req = request();
    $clientToken = (string) $req->header('X-CHAT-TOKEN', '');

    // Ưu tiên theo token để resume đúng phiên
    if ($clientToken !== '') {
        $s = DB::table('chat_sessions')->where('guest_token', $clientToken)->where('status', 'open')->first();
        if ($s) {
            session(['chat_session_id' => (int) $s->id, 'guest_chat_token' => $clientToken]);
            return response()->json([
                'success' => true,
                'has_session' => true,
                'session_id' => (int) $s->id,
                'customer_name' => $s->customer_name,
                'customer_phone' => $s->customer_phone,
            ]);
        }
    }

    $chatSessionId = (int) session('chat_session_id', 0);
    if ($chatSessionId <= 0) return response()->json(['success' => true, 'has_session' => false]);

    $s = DB::table('chat_sessions')->where('id', $chatSessionId)->first();
    if (!$s) {
        session()->forget(['chat_session_id']);
        return response()->json(['success' => true, 'has_session' => false]);
    }

    // nếu đã đóng thì không resume
    if (($s->status ?? 'open') !== 'open') {
        session()->forget(['chat_session_id']);
        return response()->json(['success' => true, 'has_session' => false]);
    }

    return response()->json([
        'success' => true,
        'has_session' => true,
        'session_id' => (int) $s->id,
        'customer_name' => $s->customer_name,
        'customer_phone' => $s->customer_phone,
    ]);
});

// Khách kết thúc cuộc trò chuyện
Route::post('/chat/end', function (Request $request) {
    if (!Schema::hasTable('chat_sessions')) {
        return response()->json(['success' => false, 'message' => 'Chat chưa sẵn sàng.'], 500);
    }

    $clientToken = (string) $request->header('X-CHAT-TOKEN', '');
    $chatSessionId = 0;
    if ($clientToken !== '' && Schema::hasTable('chat_sessions')) {
        $s = DB::table('chat_sessions')->where('guest_token', $clientToken)->where('status', 'open')->first();
        if ($s) $chatSessionId = (int) $s->id;
    }
    if ($chatSessionId <= 0) $chatSessionId = (int) session('chat_session_id', 0);
    if ($chatSessionId <= 0) {
        // Không có phiên thì coi như đã kết thúc
        session()->forget(['chat_session_id', 'guest_chat_token']);
        return response()->json(['success' => true]);
    }

    DB::table('chat_sessions')->where('id', $chatSessionId)->update([
        'status' => 'closed',
        'updated_at' => now(),
    ]);

    session()->forget(['chat_session_id', 'guest_chat_token']);

    return response()->json(['success' => true]);
});

// Khuyến mãi
Route::get('/khuyen-mai', function () {
    $promos = DB::table('sanpham')
        ->where('khuyenmai', 1)
        ->where('so_luong_con', '>', 0)
        ->get();

    return view('khuyenmai', compact('promos'));
});

/*
|--------------------------------------------------------------------------
| MENU - THỰC ĐƠN THEO DANH MỤC
|--------------------------------------------------------------------------
*/
// Chỉ cần một Route duy nhất cho menu
Route::get('/menu', [MenuController::class, 'index'])->name('menu.index');



// Chi tiết sản phẩm
Route::get('/sanpham/{id}', function ($id) {
    $sp = DB::table('sanpham')
        ->where('MaSanPham', $id)
        ->first();

    if (!$sp) return redirect('/');

    return view('sanpham.chitiet', compact('sp'));
});
Route::get('/admin/sanpham/sua/{id}', function ($id) {

    if (!session()->has('admin')) return redirect('/admin/login');

    $sp = DB::table('sanpham')->where('MaSanPham', $id)->first();

    if (!$sp) return back()->with('error', 'Không tìm thấy sản phẩm');

    return view('admin.sanpham.sua', compact('sp'));
});
Route::post('/admin/sanpham/sua/{id}', function (Request $request, $id) {

    $sp = DB::table('sanpham')->where('MaSanPham', $id)->first();

    $tenFileAnh = $sp->hinhanh;

    // Nếu có upload ảnh
    if ($request->hasFile('hinhanh')) {
        $file = $request->file('hinhanh');
        $tenFileAnh = time() . '_' . $file->getClientOriginalName();
        $file->move(public_path('images'), $tenFileAnh);
    }

    DB::table('sanpham')->where('MaSanPham', $id)->update([
        'TenSanPham' => $request->TenSanPham,
        'Gia' => $request->Gia,
        'MoTa' => $request->MoTa,
        'hinhanh' => $tenFileAnh
    ]);

    return redirect('/admin/sanpham')->with('success', 'Cập nhật thành công!');
});

/*
|--------------------------------------------------------------------------
| 2. GIỎ HÀNG + AUTH
|--------------------------------------------------------------------------
*/







   


/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

// Login
Route::view('/login', 'auth.login');





/*
|--------------------------------------------------------------------------
| 3. ADMIN
|--------------------------------------------------------------------------
*/

// Login admin
Route::view('/admin/login', 'admin.login');

Route::post('/admin/login', function (Request $request) {

    $admin = DB::table('quantrivien')
        ->where('TaiKhoan', $request->username)
        ->first();

    if ($admin && $admin->MatKhauHash == $request->password) {
        session([
            'admin' => $admin->TenQuanTri,
            'admin_id' => $admin->MaQuanTri
        ]);
        return redirect('/admin/dashboard');
    }

    return back()->with('error', 'Sai tài khoản');
});

// Dashboard
Route::get('/admin/dashboard', function () {

    if (!session()->has('admin')) return redirect('/admin/login');

    // Thống kê
    $productCount = DB::table('sanpham')->count();
    $orderCount = DB::table('donhang')->count();
    $customerCount = DB::table('khachhang')->count();
    $revenue = DB::table('donhang')->sum('TongTien');

    // 👇 THÊM ĐOẠN NÀY (đơn hàng mới)
    $donHangMoi = DB::table('donhang')
        ->join('khachhang', 'donhang.MaKhachHang', '=', 'khachhang.MaKhachHang')
        ->select('donhang.*', 'khachhang.HoVaTen as TenKhachHang')
        ->orderBy('MaDonHang', 'desc')
        ->limit(5)
        ->get();

    // Trả về view
    return view('admin.dashboard', compact(
        'productCount',
        'orderCount',
        'customerCount',
        'revenue',
        'donHangMoi' // 👈 QUAN TRỌNG
    ));
});

// Doanh thu theo ngày (đơn hàng "Đã giao")
Route::get('/admin/doanhthu', function (Request $request) {
    if (!session()->has('admin')) return redirect('/admin/login');

    $successStatus = 'Đặt hàng thành công';

    // Xác định cột ngày hợp lệ (bảng của bạn không nhất thiết có created_at)
    $dateColumn = null;
    try {
        if (\Illuminate\Support\Facades\Schema::hasColumn('donhang', 'NgayDat')) {
            $dateColumn = 'NgayDat';
        } elseif (\Illuminate\Support\Facades\Schema::hasColumn('donhang', 'created_at')) {
            $dateColumn = 'created_at';
        }
    } catch (\Throwable $e) {
        $dateColumn = 'NgayDat';
    }

    if (!$dateColumn) {
        return back()->with('error', 'Không xác định được cột ngày đặt (cần `NgayDat` hoặc `created_at` trong bảng donhang).');
    }

    // Xác định cột phương thức thanh toán
    $paymentColumn = null;
    try {
        if (\Illuminate\Support\Facades\Schema::hasColumn('donhang', 'HinhThucThanhToan')) {
            $paymentColumn = 'HinhThucThanhToan';
        } elseif (\Illuminate\Support\Facades\Schema::hasColumn('donhang', 'PhuongThucTT')) {
            $paymentColumn = 'PhuongThucTT';
        }
    } catch (\Throwable $e) {
        $paymentColumn = 'HinhThucThanhToan';
    }

    // Lọc ngày (mặc định 7 ngày gần nhất)
    $to = $request->query('to');
    $from = $request->query('from');

    try {
        $toDate = $to ? \Illuminate\Support\Carbon::parse($to)->toDateString() : now()->toDateString();
    } catch (\Throwable $e) {
        $toDate = now()->toDateString();
    }

    try {
        $fromDate = $from ? \Illuminate\Support\Carbon::parse($from)->toDateString() : now()->subDays(6)->toDateString();
    } catch (\Throwable $e) {
        $fromDate = now()->subDays(6)->toDateString();
    }

    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    $dateExpr = "DATE($dateColumn)";
    $paymentExpr = $paymentColumn ? $paymentColumn : "''";

    $byDay = DB::table('donhang')
        ->where('TrangThai', $successStatus)
        ->whereBetween(DB::raw($dateExpr), [$fromDate, $toDate])
        ->selectRaw("$dateExpr as Ngay, $paymentExpr as PhuongThuc, COUNT(*) as SoDon, SUM(TongTien) as DoanhThu")
        ->groupBy('Ngay', 'PhuongThuc')
        ->orderBy('Ngay', 'desc')
        ->orderBy('PhuongThuc', 'asc')
        ->get();

    $rangeTotal = (float) ($byDay->sum('DoanhThu') ?? 0);

    $today = now()->toDateString();
    $todayRevenue = (float) (DB::table('donhang')
        ->where('TrangThai', $successStatus)
        ->whereDate($dateColumn, $today)
        ->sum('TongTien') ?? 0);

    $todayByPayment = collect();
    $rangeByPayment = collect();
    if ($paymentColumn) {
        $todayByPayment = DB::table('donhang')
            ->where('TrangThai', $successStatus)
            ->whereDate($dateColumn, $today)
            ->selectRaw("$paymentColumn as PhuongThuc, SUM(TongTien) as DoanhThu")
            ->groupBy('PhuongThuc')
            ->orderBy('PhuongThuc', 'asc')
            ->get();

        $rangeByPayment = DB::table('donhang')
            ->where('TrangThai', $successStatus)
            ->whereBetween(DB::raw($dateExpr), [$fromDate, $toDate])
            ->selectRaw("$paymentColumn as PhuongThuc, SUM(TongTien) as DoanhThu")
            ->groupBy('PhuongThuc')
            ->orderBy('PhuongThuc', 'asc')
            ->get();
    }

    return view('admin.doanhthu.index', compact(
        'byDay',
        'fromDate',
        'toDate',
        'rangeTotal',
        'todayRevenue',
        'successStatus',
        'dateColumn',
        'paymentColumn',
        'todayByPayment',
        'rangeByPayment'
    ));
});

// Admin chat
Route::get('/admin/chat', function () {
    if (!session()->has('admin')) return redirect('/admin/login');

    if (!Schema::hasTable('chat_sessions')) {
        $sessions = collect();
        return view('admin.chat.index', ['sessions' => $sessions, 'activeSession' => null]);
    }

    $sessions = DB::table('chat_sessions')
        ->orderByRaw('COALESCE(last_message_at, updated_at) DESC')
        ->limit(50)
        ->get();

    return view('admin.chat.index', ['sessions' => $sessions, 'activeSession' => null]);
});

Route::get('/admin/chat/{sid}', function ($sid) {
    if (!session()->has('admin')) return redirect('/admin/login');

    $sessions = Schema::hasTable('chat_sessions')
        ? DB::table('chat_sessions')->orderByRaw('COALESCE(last_message_at, updated_at) DESC')->limit(50)->get()
        : collect();

    $activeSession = Schema::hasTable('chat_sessions')
        ? DB::table('chat_sessions')->where('id', $sid)->first()
        : null;

    return view('admin.chat.index', compact('sessions', 'activeSession'));
});

Route::get('/admin/chat/{sid}/fetch', function (Request $request, $sid) {
    if (!session()->has('admin')) return response()->json(['success' => false], 401);
    if (!Schema::hasTable('chat_messages')) return response()->json(['success' => false], 500);

    $afterId = (int) $request->query('after_id', 0);
    $rows = DB::table('chat_messages')
        ->where('chat_session_id', (int) $sid)
        ->where('id', '>', $afterId)
        ->orderBy('id', 'asc')
        ->limit(80)
        ->get();

    $messages = $rows->map(function ($m) {
        return [
            'id' => (int) $m->id,
            'sender_type' => $m->sender_type,
            'sender_name' => $m->sender_name,
            'message' => $m->message,
            'created_at_fmt' => $m->created_at ? date('H:i d/m', strtotime($m->created_at)) : '',
        ];
    });

    return response()->json(['success' => true, 'messages' => $messages]);
});

// Admin long-poll (nhận tin gần realtime)
Route::get('/admin/chat/{sid}/poll', function (Request $request, $sid) {
    if (!session()->has('admin')) return response()->json(['success' => false], 401);
    if (!Schema::hasTable('chat_messages')) return response()->json(['success' => false], 500);

    $afterId = (int) $request->query('after_id', 0);
    $deadlineMs = (int) $request->query('timeout_ms', 20000);
    if ($deadlineMs < 2000) $deadlineMs = 2000;
    if ($deadlineMs > 25000) $deadlineMs = 25000;

    $start = microtime(true);
    while (true) {
        $rows = DB::table('chat_messages')
            ->where('chat_session_id', (int) $sid)
            ->where('id', '>', $afterId)
            ->orderBy('id', 'asc')
            ->limit(80)
            ->get();

        if ($rows->count() > 0) {
            $messages = $rows->map(function ($m) {
                return [
                    'id' => (int) $m->id,
                    'sender_type' => $m->sender_type,
                    'sender_name' => $m->sender_name,
                    'message' => $m->message,
                    'created_at_fmt' => $m->created_at ? date('H:i d/m', strtotime($m->created_at)) : '',
                ];
            });
            return response()->json(['success' => true, 'messages' => $messages]);
        }

        $elapsedMs = (int) ((microtime(true) - $start) * 1000);
        if ($elapsedMs >= $deadlineMs) {
            return response()->json(['success' => true, 'messages' => []]);
        }

        usleep(100000); // 100ms
    }
});

Route::post('/admin/chat/{sid}/send', function (Request $request, $sid) {
    if (!session()->has('admin')) return response()->json(['success' => false], 401);
    if (!Schema::hasTable('chat_messages') || !Schema::hasTable('chat_sessions')) return response()->json(['success' => false], 500);

    $request->validate([
        'message' => ['required', 'string', 'max:2000'],
    ]);

    $adminName = (string) session('admin');
    if ($adminName === '') $adminName = 'Tư vấn viên';

    $text = trim((string) $request->message);

    // Chặn gửi trùng phía admin (double submit)
    $last = DB::table('chat_messages')
        ->where('chat_session_id', (int) $sid)
        ->where('sender_type', 'admin')
        ->orderByDesc('id')
        ->first();
    if ($last && isset($last->message) && $last->message === $text) {
        $lastAt = $last->created_at ? strtotime($last->created_at) : null;
        if ($lastAt && (time() - $lastAt) <= 2) {
            return response()->json(['success' => true, 'message_id' => (int) $last->id]);
        }
    }

    $msgId = DB::table('chat_messages')->insertGetId([
        'chat_session_id' => (int) $sid,
        'sender_type' => 'admin',
        'sender_name' => $adminName,
        'message' => $text,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('chat_sessions')->where('id', (int) $sid)->update([
        'last_message_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json(['success' => true, 'message_id' => (int) $msgId]);
});
// ================== QUẢN LÝ SẢN PHẨM ==================

// FORM THÊM SẢN PHẨM
Route::get('/admin/sanpham/them', function () {

    if (!session()->has('admin')) return redirect('/admin/login');

    $danhmuc = DB::table('danhmuc')->get();

    return view('admin.sanpham.them', compact('danhmuc'));
});

// XỬ LÝ THÊM SẢN PHẨM
Route::post('/admin/sanpham/store', function (Request $request) {
    $tenFileAnh = '';
    if ($request->hasFile('HinhAnh')) {
        $file = $request->file('HinhAnh');
        $tenFileAnh = time() . '_' . $file->getClientOriginalName();
        $file->move(public_path('images'), $tenFileAnh);
    }

    DB::table('sanpham')->insert([
        'TenSanPham' => $request->TenSanPham,
        'MaDanhMuc' => $request->MaDanhMuc,
        'Gia' => $request->Gia,
        'gia_giam' => $request->gia_giam, // <--- THÊM DÒNG NÀY
        'so_luong_con' => $request->so_luong_con,
        'hinhanh' => $tenFileAnh,
        'MoTa' => $request->MoTa,
        // Tự động bật khuyến mãi nếu có nhập giá giảm
        'khuyenmai' => ($request->gia_giam > 0) ? 1 : 0 
    ]);

    return redirect('/admin/sanpham')->with('success', 'Thêm thành công!');
});
// Danh sách sản phẩm


Route::post('/admin/sanpham/sua/{id}', function (Request $request, $id) {
    $sp = DB::table('sanpham')->where('MaSanPham', $id)->first();
    $tenFileAnh = $sp->hinhanh;

    if ($request->hasFile('hinhanh')) {
        $file = $request->file('hinhanh');
        $tenFileAnh = time() . '_' . $file->getClientOriginalName();
        $file->move(public_path('images'), $tenFileAnh);
    }

    DB::table('sanpham')->where('MaSanPham', $id)->update([
        'TenSanPham' => $request->TenSanPham,
        'Gia' => $request->Gia,
        'gia_giam' => $request->gia_giam, // <--- THÊM DÒNG NÀY
        'MoTa' => $request->MoTa,
        'hinhanh' => $tenFileAnh,
        // Cập nhật lại trạng thái khuyến mãi
        'khuyenmai' => ($request->gia_giam > 0) ? 1 : 0
    ]);

    return redirect('/admin/sanpham')->with('success', 'Cập nhật thành công!');
});
// ================== QUẢN LÝ ĐƠN HÀNG ==================

// Danh sách đơn hàng
Route::get('/admin/donhang', function () {

    if (!session()->has('admin')) return redirect('/admin/login');

    // Chuẩn hoá trạng thái cũ: "Đang giao" -> "Đặt hàng thành công"
    try {
        DB::table('donhang')
            ->where('TrangThai', 'Đang giao')
            ->update(['TrangThai' => 'Đặt hàng thành công']);
    } catch (\Throwable $e) {
        // best-effort
    }

    $donhang = DB::table('donhang')
        ->join('khachhang', 'donhang.MaKhachHang', '=', 'khachhang.MaKhachHang')
        ->select(
            'donhang.*',
            'khachhang.HoVaTen as TenKhachHang',
            'khachhang.Email as EmailKhachHang',
            'khachhang.DienThoai as SDTKhachHang'
        )
        ->orderBy('MaDonHang', 'desc')
        ->get();

    return view('admin.donhang.index', compact('donhang'));
});

// Cập nhật trạng thái
Route::post('/admin/donhang/update/{id}', function (Request $request, $id) {

    DB::table('donhang')
        ->where('MaDonHang', $id)
        ->update([
            'TrangThai' => $request->TrangThai
        ]);

    return back()->with('success', 'Cập nhật thành công!');
});

// Chi tiết đơn hàng (xem thông tin KH + sản phẩm)
Route::get('/admin/donhang/{id}', function ($id) {
    if (!session()->has('admin')) return redirect('/admin/login');

    // Chuẩn hoá trạng thái cũ: "Đang giao" -> "Đặt hàng thành công"
    try {
        DB::table('donhang')
            ->where('MaDonHang', $id)
            ->where('TrangThai', 'Đang giao')
            ->update(['TrangThai' => 'Đặt hàng thành công']);
    } catch (\Throwable $e) {
        // best-effort
    }

    $donhang = DB::table('donhang')
        ->join('khachhang', 'donhang.MaKhachHang', '=', 'khachhang.MaKhachHang')
        ->select(
            'donhang.*',
            'khachhang.HoVaTen as TenKhachHang',
            'khachhang.Email as EmailKhachHang',
            'khachhang.DienThoai as SDTKhachHang'
        )
        ->where('donhang.MaDonHang', $id)
        ->first();

    if (!$donhang) return back()->with('error', 'Không tìm thấy đơn hàng');

    $items = [];
    try {
        $items = DB::table('chitietdonhang')
            ->leftJoin('sanpham', 'chitietdonhang.MaSanPham', '=', 'sanpham.MaSanPham')
            ->where('chitietdonhang.MaDonHang', $id)
            ->select(
                'chitietdonhang.*',
                'sanpham.TenSanPham as TenSanPham2',
                'sanpham.hinhanh as HinhAnh2'
            )
            ->get();
    } catch (\Throwable $e) {
        $items = collect();
    }

    return view('admin.donhang.show', compact('donhang', 'items'));
});

// Khôi phục chi tiết đơn hàng (best-effort cho đơn cũ)
Route::post('/admin/donhang/{id}/backfill-items', function ($id) {
    if (!session()->has('admin')) return redirect('/admin/login');

    try {
        if (!\Illuminate\Support\Facades\Schema::hasTable('chitietdonhang')) {
            return back()->with('error', 'Thiếu bảng `chitietdonhang` để lưu chi tiết.');
        }

        $existing = \Illuminate\Support\Facades\DB::table('chitietdonhang')
            ->where('MaDonHang', $id)
            ->count();
        if ($existing > 0) {
            return back()->with('success', 'Đơn hàng này đã có chi tiết sản phẩm rồi.');
        }

        // 1) Thử tìm bảng chi tiết cũ (nếu tồn tại)
        $legacyTables = [
            'ctdonhang',
            'chi_tiet_don_hang',
            'chitiet_donhang',
            'order_items',
            'order_item',
            'donhang_items',
            'donhang_item',
        ];

        foreach ($legacyTables as $t) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($t)) continue;

            $cols = \Illuminate\Support\Facades\Schema::getColumnListing($t);
            $hasOrderKey = in_array('MaDonHang', $cols, true) || in_array('order_id', $cols, true);
            if (!$hasOrderKey) continue;

            $orderKey = in_array('MaDonHang', $cols, true) ? 'MaDonHang' : 'order_id';
            $rows = \Illuminate\Support\Facades\DB::table($t)->where($orderKey, $id)->get();
            if ($rows->count() === 0) continue;

            $ctCols = \Illuminate\Support\Facades\Schema::getColumnListing('chitietdonhang');

            $inserted = 0;
            foreach ($rows as $r) {
                $data = [
                    'MaDonHang' => (int) $id,
                    'MaSanPham' => (int) (($r->MaSanPham ?? $r->product_id ?? 0) ?: 0),
                    'SoLuong' => (int) (($r->SoLuong ?? $r->quantity ?? 0) ?: 0),
                    'DonGia' => (float) (($r->DonGia ?? $r->price ?? 0) ?: 0),
                    'GiaGoc' => (float) (($r->GiaGoc ?? $r->original_price ?? 0) ?: 0),
                    'TenSanPham' => $r->TenSanPham ?? $r->name ?? null,
                    'HinhAnh' => $r->HinhAnh ?? $r->image ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $data = array_intersect_key($data, array_flip($ctCols));

                if (!isset($data['SoLuong']) || (int) $data['SoLuong'] <= 0) continue;
                if (!isset($data['MaSanPham']) || (int) $data['MaSanPham'] <= 0) continue;

                \Illuminate\Support\Facades\DB::table('chitietdonhang')->insert($data);
                $inserted++;
            }

            if ($inserted > 0) {
                return back()->with('success', "Đã khôi phục $inserted sản phẩm từ bảng `$t`.");
            }
        }

        // 2) Thử khôi phục từ cột JSON trong bảng donhang (nếu có)
        if (\Illuminate\Support\Facades\Schema::hasTable('donhang')) {
            $orderCols = \Illuminate\Support\Facades\Schema::getColumnListing('donhang');
            $jsonCandidates = ['items', 'Items', 'cart', 'Cart', 'giohang', 'GioHang', 'ChiTiet', 'chitiet'];

            $foundCol = null;
            foreach ($jsonCandidates as $c) {
                if (in_array($c, $orderCols, true)) { $foundCol = $c; break; }
            }

            if ($foundCol) {
                $order = \Illuminate\Support\Facades\DB::table('donhang')->where('MaDonHang', $id)->first();
                $raw = $order?->{$foundCol} ?? null;

                $decoded = null;
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                }

                if (is_array($decoded)) {
                    $ctCols = \Illuminate\Support\Facades\Schema::getColumnListing('chitietdonhang');
                    $inserted = 0;

                    foreach ($decoded as $it) {
                        if (!is_array($it)) continue;

                        $data = [
                            'MaDonHang' => (int) $id,
                            'MaSanPham' => (int) (($it['MaSanPham'] ?? $it['product_id'] ?? 0) ?: 0),
                            'SoLuong' => (int) (($it['SoLuong'] ?? $it['quantity'] ?? 0) ?: 0),
                            'DonGia' => (float) (($it['DonGia'] ?? $it['Gia'] ?? $it['price'] ?? 0) ?: 0),
                            'GiaGoc' => (float) (($it['GiaGoc'] ?? $it['original_price'] ?? 0) ?: 0),
                            'TenSanPham' => $it['TenSanPham'] ?? $it['name'] ?? null,
                            'HinhAnh' => $it['HinhAnh'] ?? $it['hinhanh'] ?? $it['image'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        $data = array_intersect_key($data, array_flip($ctCols));

                        if (!isset($data['SoLuong']) || (int) $data['SoLuong'] <= 0) continue;
                        if (!isset($data['MaSanPham']) || (int) $data['MaSanPham'] <= 0) continue;

                        \Illuminate\Support\Facades\DB::table('chitietdonhang')->insert($data);
                        $inserted++;
                    }

                    if ($inserted > 0) {
                        return back()->with('success', "Đã khôi phục $inserted sản phẩm từ cột `$foundCol` (JSON).");
                    }
                }
            }
        }

        return back()->with('error', 'Không tìm thấy nguồn dữ liệu cũ để khôi phục chi tiết cho đơn này.');
    } catch (\Throwable $e) {
        return back()->with('error', 'Không thể khôi phục chi tiết: ' . $e->getMessage());
    }
});
// ================== KHUYẾN MÃI ==================

// Trang khuyến mãi
Route::get('/admin/khuyenmai', function () {

    if (!session()->has('admin')) return redirect('/admin/login');

    $sanpham = DB::table('sanpham')->get();

    return view('admin.khuyenmai.index', compact('sanpham'));
});

// Cập nhật khuyến mãi
Route::post('/admin/khuyenmai/update', function (Request $request) {

    // reset tất cả về không khuyến mãi
    DB::table('sanpham')->update(['khuyenmai' => 0]);

    // nếu có chọn checkbox
    if ($request->ids) {
        DB::table('sanpham')
            ->whereIn('MaSanPham', $request->ids)
            ->update(['khuyenmai' => 1]);
    }

    return back()->with('success', 'Cập nhật khuyến mãi thành công!');
});
// QUẢN LÝ KHÁCH HÀNG
Route::get('/admin/khachhang', function () {

    if (!session()->has('admin')) return redirect('/admin/login');

    $khachhang = DB::table('khachhang')->get();

    return view('admin.khachhang.index', compact('khachhang'));
});

// Form sửa khách hàng
Route::get('/admin/khachhang/sua/{id}', function ($id) {
    if (!session()->has('admin')) return redirect('/admin/login');

    $kh = DB::table('khachhang')->where('MaKhachHang', $id)->first();
    if (!$kh) return redirect('/admin/khachhang')->with('error', 'Không tìm thấy khách hàng!');

    return view('admin.khachhang.sua', compact('kh'));
});

// Cập nhật khách hàng
Route::post('/admin/khachhang/sua/{id}', function (Request $request, $id) {
    if (!session()->has('admin')) return redirect('/admin/login');

    DB::table('khachhang')->where('MaKhachHang', $id)->update([
        'HoVaTen' => $request->HoVaTen,
        'Email' => $request->Email,
        'DienThoai' => $request->DienThoai,
        'diachi' => $request->diachi,
    ]);

    return redirect('/admin/khachhang')->with('success', 'Cập nhật khách hàng thành công!');
});

// Xóa khách hàng
Route::get('/admin/khachhang/xoa/{id}', function ($id) {
    if (!session()->has('admin')) return redirect('/admin/login');

    DB::table('khachhang')->where('MaKhachHang', $id)->delete();

    return redirect('/admin/khachhang')->with('success', 'Đã xóa khách hàng!');
});

// QUẢN LÝ SẢN PHẨM
Route::get('/admin/sanpham', function () {

    if (!session()->has('admin')) return redirect('/admin/login');

    $sanpham = DB::table('sanpham')->get();

    return view('admin.sanpham.index', compact('sanpham'));
});

// Xóa sản phẩm
Route::get('/admin/sanpham/xoa/{id}', function ($id) {
    if (!session()->has('admin')) return redirect('/admin/login');

    DB::table('sanpham')->where('MaSanPham', $id)->delete();

    return redirect('/admin/sanpham')->with('success', 'Đã xóa sản phẩm!');
});
/*
|--------------------------------------------------------------------------
| AUTH & GIỎ HÀNG (ĐÃ TỐI ƯU CHO AJAX)
|--------------------------------------------------------------------------
*/


// Auth khách hàng
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::get('/logout', [AuthController::class, 'logout'])->name('logout');

// 1. Xem trang giỏ hàng
Route::get('/cart', [CartController::class, 'viewCart'])->name('cart.index');

// 2. Xử lý thêm sản phẩm (ĐÃ SỬA: Bỏ {id} để không bị lỗi Missing Parameter)
Route::post('/cart/add', [App\Http\Controllers\CartController::class, 'addToCart'])->name('cart.add');

// 3. Cập nhật và Xóa
Route::post('/cart/update/{id}', [CartController::class, 'update'])->name('cart.update');
Route::get('/cart/delete/{id}', [CartController::class, 'deleteItem'])->name('cart.delete');

// 4. Thanh toán
Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
Route::post('/checkout/process', [CheckoutController::class, 'placeOrder'])->name('checkout.process');
Route::get('/checkout/success', [CheckoutController::class, 'success'])->name('checkout.success');


