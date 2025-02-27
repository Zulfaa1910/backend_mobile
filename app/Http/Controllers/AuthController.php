<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function register(Request $req)
    {
        // Validasi input
        $rules = [
            'name' => 'required|string',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string|min:6',
            'phone' => 'required|string|unique:users',
            'birthdate' => 'required|date',
            'gender' => 'required|in:male,female,other',
            'address' => 'required|string',
        ];

        $validator = Validator::make($req->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        // Ambil merk hp dari User-Agent
        $merk_hp = $this->getDeviceInfo($req);

        // Buat kode verifikasi unik dan kode sales
        $verification_code = Str::random(6);
        $kode_unik = $this->generateKodeUnik();
        $kode_sales = $this->generateKodeSales();

        // Simpan user baru
        $user = User::create([
            'name' => $req->name,
            'email' => $req->email,
            'password' => Hash::make($req->password),
            'phone' => $req->phone,
            'verification_code' => $verification_code,
            'birthdate' => $req->birthdate,
            'gender' => $req->gender,
            'address' => $req->address,
            'kode_unik' => $kode_unik, // Menyimpan kode unik
            'kode_sales' => $kode_sales, // Menyimpan kode sales
            'merk_hp' => $merk_hp // Menyimpan merk hp
        ]);

        // Buat token
        $token = $user->createToken('Personal Access Token')->plainTextToken;

        // Prepare the response
        $response = [
            'user' => $user,
            'token' => $token,
            'kode_unik' => $kode_unik,  // Sertakan kode unik dalam respon
            'kode_sales' => $kode_sales   // Sertakan kode sales dalam respon
        ];

        return response()->json($response, 200);
    }

    // Fungsi untuk mendapatkan merk hp dari User-Agent
    private function getDeviceInfo(Request $req)
    {
        $userAgent = $req->header('User-Agent');

        // Contoh parsing sederhana menggunakan regex
        if (preg_match('/\((.*?)\)/', $userAgent, $matches)) {
            return $matches[1]; // Mengambil informasi dalam tanda kurung
        }

        return 'Unknown Device'; // Jika tidak ada yang ditemukan
    }

    // Fungsi untuk generate kode unik
    private function generateKodeUnik()
    {
        return 'SL' . time() . Str::random(4);  // Contoh: SL1695833450ABCD
    }

    // Fungsi untuk generate kode sales
    private function generateKodeSales()
    {
        $lastUser = User::orderBy('kode_sales', 'desc')->first();

        if (!$lastUser) {
            return 'SL000001';  // Jika belum ada kode sales, mulai dari SL000001
        }

        // Ambil angka dari kode terakhir
        $lastKodeSales = $lastUser->kode_sales;
        $lastNumber = intval(substr($lastKodeSales, 2)); // Mengambil angka setelah 'SL'

        // Increment angkanya
        $newNumber = $lastNumber + 1;

        // Membuat kode sales baru dengan leading zeros (SL000001, SL000002, dst.)
        return 'SL' . str_pad($newNumber, 6, '0', STR_PAD_LEFT);
    }

    public function login(Request $req)
    {
        // Validasi input
        $rules = [
            'email' => 'required|string|email',
            'password' => 'required|string'
        ];
        $req->validate($rules);

        // Cari user berdasarkan email
        $user = User::where('email', $req->email)->first();

        if ($user && Hash::check($req->password, $user->password)) {
            // Cek apakah nomor telepon sudah diverifikasi
            if (!$user->phone_verified_at) {
                return response()->json(['message' => 'Phone number not verified.'], 403);
            }

            // Buat token
            $token = $user->createToken('Personal Access Token')->plainTextToken;
            $response = ['user' => $user, 'token' => $token];
            return response()->json($response, 200);
        }

        return response()->json(['message' => 'Incorrect email or password'], 401);
    }

    // Verifikasi kode yang dikirim ke telepon
    public function verifyPhone(Request $req)
    {
        $req->validate([
            'phone' => 'required|string',
            'verification_code' => 'required|string'
        ]);

        $user = User::where('phone', $req->phone)
                    ->where('verification_code', $req->verification_code)
                    ->first();

        if ($user) {
            $user->phone_verified_at = now();
            $user->verification_code = null;  // Hapus kode verifikasi setelah diverifikasi
            $user->save();

            return response()->json(['message' => 'Phone verified successfully.'], 200);
        }

        return response()->json(['message' => 'Invalid verification code.'], 400);
    }
}
