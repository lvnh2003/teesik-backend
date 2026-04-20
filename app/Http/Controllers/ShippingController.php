<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Shipping\GhnShippingService;

class ShippingController extends Controller
{
    protected $ghnService;

    public function __construct(GhnShippingService $ghnService)
    {
        $this->ghnService = $ghnService;
    }

    public function getProvinces()
    {
        $data = $this->ghnService->getProvinces();
        return $this->successResponse($data);
    }

    public function getDistricts(Request $request)
    {
        $provinceId = $request->query('province_id');
        if (!$provinceId) {
            return $this->errorResponse('province_id is required', 400);
        }

        $data = $this->ghnService->getDistricts($provinceId);
        return $this->successResponse($data);
    }

    public function getWards(Request $request)
    {
        $districtId = $request->query('district_id');
        if (!$districtId) {
            return $this->errorResponse('district_id is required', 400);
        }

        $data = $this->ghnService->getWards($districtId);
        return $this->successResponse($data);
    }

    public function calculateFee(Request $request)
    {
        $validated = $request->validate([
            'district_id' => 'required',
            'ward_code' => 'required',
            'total_value' => 'nullable|numeric',
            'weight' => 'nullable|numeric'
        ]);

        $districtId = $validated['district_id'];
        $wardCode = $validated['ward_code'];
        $weight = $validated['weight'] ?? 300; 
        $totalValue = $validated['total_value'] ?? 0;

        $fee = $this->ghnService->calculateFee($districtId, $wardCode, $weight, $totalValue);

        return $this->successResponse([
            'fee' => $fee
        ]);
    }

}
