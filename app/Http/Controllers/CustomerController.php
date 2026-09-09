<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $customer = Customer::query()
            ->where('email', strtolower(trim($validated['email'])))
            ->first(['id', 'name', 'email']);

        return response()->json(['data' => $customer]);
    }
}
