<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FinanceController extends Controller
{
    public function storeFeeStructure(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'class_id' => 'nullable|exists:classes,id',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $fee = FeeStructure::create([
            'school_id' => $schoolId,
            'term_id' => $request->term_id,
            'class_id' => $request->class_id,
            'title' => $request->title,
            'amount' => $request->amount,
            'is_mandatory' => $request->get('is_mandatory', true),
        ]);

        return response()->json([
            'message' => 'Fee structure created successfully',
            'fee_structure' => $fee,
        ], 201);
    }

    public function recordPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:1',
            'gateway' => 'required|in:paystack,flutterwave,bank_transfer,cash',
            'reference' => 'required|string|unique:payments,reference',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $invoice = Invoice::where('school_id', $schoolId)->findOrFail($request->invoice_id);

        $payment = Payment::create([
            'school_id' => $schoolId,
            'invoice_id' => $invoice->id,
            'reference' => $request->reference,
            'amount' => $request->amount,
            'gateway' => $request->gateway,
            'status' => 'successful',
            'paid_at' => now(),
        ]);

        $invoice->amount_paid += $request->amount;
        if ($invoice->amount_paid >= $invoice->total_amount) {
            $invoice->status = 'paid';
        } else {
            $invoice->status = 'partial';
        }
        $invoice->save();

        return response()->json([
            'message' => 'Payment recorded successfully',
            'payment' => $payment,
            'invoice' => $invoice,
        ]);
    }

    public function handleWebhook(Request $request, $gateway)
    {
        // Webhook signature verification placeholder
        $signature = $request->header('x-paystack-signature') ?? $request->header('verif-hash');

        if (!$signature) {
            return response()->json(['message' => 'Invalid or missing webhook signature'], 400);
        }

        return response()->json(['message' => 'Webhook received and processed cleanly']);
    }
}
