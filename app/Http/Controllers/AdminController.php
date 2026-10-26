<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function loginForm() { return view('admin.login'); }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required','email'], 'password' => ['required','string']]);
        if (! Auth::attempt($data) || Auth::user()->role !== 'admin') {
            Auth::logout();
            return back()->withErrors(['email' => 'Invalid admin credentials.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        return redirect()->route('admin.dashboard');
    }

    public function dashboard()
    {
        return view('admin.dashboard', [
            'sellerCount' => User::where('role', 'seller')->count(),
            'sellers' => User::where('role', 'seller')->latest()->paginate(10),
        ]);
    }

    public function createSeller(Request $request)
    {
        $data = $request->validate([
            'seller_name' => ['required','string','max:120'],
            'phone' => ['required','string','regex:/^\+[1-9]\d{7,14}$/','unique:users,phone'],
            'address' => ['required','string','max:500'],
            'landmark' => ['required','string','max:200'],
            'state' => ['required','string','max:100'],
            'country' => ['required','string','max:100'],
            'password' => ['required','string','min:8','confirmed'],
        ]);
        User::create([
            'name' => $data['seller_name'],
            'email' => 'seller_' . preg_replace('/\D+/', '', $data['phone']) . '@phone.venter.local',
            'seller_name' => $data['seller_name'], 'phone' => $data['phone'],
            'address' => $data['address'], 'landmark' => $data['landmark'],
            'state' => $data['state'], 'country' => $data['country'],
            'password' => Hash::make($data['password']), 'role' => 'seller',
        ]);
        return redirect()->route('admin.dashboard')->with('success', 'Seller account created successfully.');
    }

    public function logout(Request $request)
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }
}
