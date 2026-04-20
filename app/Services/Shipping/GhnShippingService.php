<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GhnShippingService
{
    protected $token;
    protected $shopId;
    protected $baseUrl;
    protected $fallbackFee = 30000;

    public function __construct()
    {
        $this->token = env('GHN_API_TOKEN', '');
        $this->shopId = env('GHN_SHOP_ID', '');
        $this->baseUrl = 'https://online-gateway.ghn.vn/shiip/public-api';
    }

    protected function getHeaders()
    {
        return [
            'Token' => $this->token,
            'ShopId' => $this->shopId,
            'Content-Type' => 'application/json'
        ];
    }

    public function getProvinces()
    {
        if (empty($this->token)) {
            return $this->mockProvinces();
        }

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->get("{$this->baseUrl}/master-data/province");

            if ($response->successful()) {
                $data = $response->json();
                return collect($data['data'])->map(function($item) {
                    return [
                        'province_id' => $item['ProvinceID'],
                        'province_name' => $item['ProvinceName']
                    ];
                })->toArray();
            }
        } catch (\Exception $e) {
            Log::error("GHN getProvinces Error: " . $e->getMessage());
        }

        return $this->mockProvinces();
    }

    public function getDistricts($provinceId)
    {
        if (empty($this->token)) {
            return $this->mockDistricts($provinceId);
        }

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->get("{$this->baseUrl}/master-data/district", [
                    'province_id' => $provinceId
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return collect($data['data'])->map(function($item) {
                    return [
                        'district_id' => $item['DistrictID'],
                        'district_name' => $item['DistrictName']
                    ];
                })->toArray();
            }
        } catch (\Exception $e) {
            Log::error("GHN getDistricts Error: " . $e->getMessage());
        }

        return $this->mockDistricts($provinceId);
    }

    public function getWards($districtId)
    {
        if (empty($this->token)) {
            return $this->mockWards($districtId);
        }

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->get("{$this->baseUrl}/master-data/ward", [
                    'district_id' => $districtId
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return collect($data['data'])->map(function($item) {
                    return [
                        'ward_code' => $item['WardCode'],
                        'ward_name' => $item['WardName']
                    ];
                })->toArray();
            }
        } catch (\Exception $e) {
            Log::error("GHN getWards Error: " . $e->getMessage());
        }

        return $this->mockWards($districtId);
    }

    public function calculateFee($districtId, $wardCode, $weight = 200, $value = 0)
    {
        if (empty($this->token) || empty($this->shopId)) {
            return $this->fallbackFee;
        }

        try {
            $payload = [
                'from_district_id' => 1454, // Example: Quận 1, TPHCM
                'from_ward_code' => "21211", // Example: P.Bến Nghé
                'service_type_id' => 2, // 2: Chuyển phát nhanh
                'to_district_id' => (int)$districtId,
                'to_ward_code' => (string)$wardCode,
                'weight' => (int)$weight,
                'length' => 15,
                'width' => 15,
                'height' => 15,
                'insurance_value' => (int)$value,
            ];

            $response = Http::withHeaders($this->getHeaders())
                ->post("{$this->baseUrl}/v2/shipping-order/fee", $payload);

            if ($response->successful()) {
                $data = $response->json();
                return $data['data']['total'] ?? $this->fallbackFee;
            }
        } catch (\Exception $e) {
            Log::error("GHN calculateFee Error: " . $e->getMessage());
        }

        return $this->fallbackFee;
    }

    // Mock data fallbacks for environments without GHN keys
    private function mockProvinces()
    {
        return [
            ['province_id' => 201, 'province_name' => 'Hà Nội'],
            ['province_id' => 202, 'province_name' => 'Hồ Chí Minh'],
            ['province_id' => 203, 'province_name' => 'Đà Nẵng'],
        ];
    }

    private function mockDistricts($provinceId)
    {
        if ($provinceId == 202) {
            return [
                ['district_id' => 1442, 'district_name' => 'Quận 1'],
                ['district_id' => 1443, 'district_name' => 'Quận 2'],
                ['district_id' => 1444, 'district_name' => 'Quận 3'],
            ];
        }
        return [
            ['district_id' => 1500, 'district_name' => 'Huyện Cầu Giấy'],
            ['district_id' => 1501, 'district_name' => 'Huyện Đống Đa'],
        ];
    }

    private function mockWards($districtId)
    {
        return [
            ['ward_code' => "W1001", 'ward_name' => 'Phường 1'],
            ['ward_code' => "W1002", 'ward_name' => 'Phường 2'],
            ['ward_code' => "W1003", 'ward_name' => 'Phường 3'],
        ];
    }
}
