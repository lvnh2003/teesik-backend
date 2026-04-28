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
        $this->token = config('services.ghn.token', '');
        $this->shopId = config('services.ghn.shop_id', '');
        $this->baseUrl = 'https://online-gateway.ghn.vn/shiip/public-api';
    }

    protected function getHeaders($includeShop = true)
    {
        $headers = [
            'Token' => $this->token,
            'Content-Type' => 'application/json'
        ];

        if ($includeShop && !empty($this->shopId)) {
            $headers['ShopId'] = (int)$this->shopId;
        }

        return $headers;
    }

    public function getProvinces()
    {
        if (empty($this->token)) {
            Log::warning("GHN getProvinces: No API Token configured. Falling back to mock.");
            return $this->mockProvinces();
        }

        try {
            // Master data doesn't usually need ShopId
            $response = Http::withHeaders($this->getHeaders(false))
                ->timeout(5)
                ->get("{$this->baseUrl}/master-data/province");

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['data'])) {
                    return collect($data['data'])->map(function($item) {
                        return [
                            'province_id' => $item['ProvinceID'],
                            'province_name' => $item['ProvinceName']
                        ];
                    })->toArray();
                }
                Log::error("GHN getProvinces: Unexpected response structure", ['response' => $data]);
            } else {
                Log::error("GHN getProvinces Failed", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error("GHN getProvinces Exception: " . $e->getMessage());
        }

        return $this->mockProvinces();
    }

    public function getDistricts($provinceId)
    {
        if (empty($this->token)) {
            return $this->mockDistricts($provinceId);
        }

        try {
            $response = Http::withHeaders($this->getHeaders(false))
                ->timeout(5)
                ->get("{$this->baseUrl}/master-data/district", [
                    'province_id' => (int)$provinceId
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['data'])) {
                    return collect($data['data'])->map(function($item) {
                        return [
                            'district_id' => $item['DistrictID'],
                            'district_name' => $item['DistrictName']
                        ];
                    })->toArray();
                }
                Log::error("GHN getDistricts: Unexpected response structure", ['response' => $data]);
            } else {
                Log::error("GHN getDistricts Failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'province_id' => $provinceId
                ]);
            }
        } catch (\Exception $e) {
            Log::error("GHN getDistricts Exception: " . $e->getMessage());
        }

        return $this->mockDistricts($provinceId);
    }

    public function getWards($districtId)
    {
        if (empty($this->token)) {
            return $this->mockWards($districtId);
        }

        try {
            $response = Http::withHeaders($this->getHeaders(false))
                ->timeout(5)
                ->get("{$this->baseUrl}/master-data/ward", [
                    'district_id' => (int)$districtId
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['data']) && is_array($data['data'])) {
                    return collect($data['data'])->map(function($item) {
                        return [
                            'ward_code' => (string)$item['WardCode'],
                            'ward_name' => $item['WardName']
                        ];
                    })->toArray();
                }
                Log::error("GHN getWards: Unexpected response structure or null data", ['response' => $data]);
            } else {
                Log::error("GHN getWards Failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'district_id' => $districtId
                ]);
            }
        } catch (\Exception $e) {
            Log::error("GHN getWards Exception: " . $e->getMessage());
        }

        return $this->mockWards($districtId);
    }

    public function calculateFee($districtId, $wardCode, $weight = 200, $value = 0)
    {
        if (empty($this->token) || empty($this->shopId)) {
            Log::warning("GHN calculateFee: Missing Token or ShopId. Falling back to default fee.");
            return $this->fallbackFee;
        }

        try {
            $payload = [
                'from_district_id' => 1454, // Quận 1, TPHCM
                'from_ward_code' => "21211", // P.Bến Nghé
                'service_type_id' => 2, // 2: Chuyển phát nhanh
                'to_district_id' => (int)$districtId,
                'to_ward_code' => (string)$wardCode,
                'weight' => (int)$weight,
                'length' => 15,
                'width' => 15,
                'height' => 15,
                'insurance_value' => (int)$value,
            ];

            $response = Http::withHeaders($this->getHeaders(true))
                ->timeout(5)
                ->post("{$this->baseUrl}/v2/shipping-order/fee", $payload);

            if ($response->successful()) {
                $data = $response->json();
                return $data['data']['total'] ?? $this->fallbackFee;
            } else {
                $body = $response->json();
                $errorCode = $body['code_message'] ?? $body['code'] ?? 'UNKNOWN';
                $message = $body['message'] ?? 'No message';

                if ($errorCode === 'CLIENT_NOT_BELONG_OF_SHOP') {
                    Log::critical("GHN CONFIG ERROR: Your GHN_API_TOKEN and GHN_SHOP_ID do not match. Please verify them in your .env file.");
                }

                Log::error("GHN calculateFee Failed", [
                    'status' => $response->status(),
                    'code' => $errorCode,
                    'message' => $message,
                    'payload' => $payload
                ]);
            }
        } catch (\Exception $e) {
            Log::error("GHN calculateFee Exception: " . $e->getMessage());
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
            ['province_id' => 204, 'province_name' => 'Hải Phòng'],
            ['province_id' => 205, 'province_name' => 'Cần Thơ'],
            ['province_id' => 206, 'province_name' => 'An Giang'],
            ['province_id' => 207, 'province_name' => 'Bà Rịa - Vũng Tàu'],
            ['province_id' => 208, 'province_name' => 'Bắc Giang'],
            ['province_id' => 209, 'province_name' => 'Bắc Ninh'],
            ['province_id' => 210, 'province_name' => 'Bình Dương'],
            ['province_id' => 211, 'province_name' => 'Bình Thuận'],
            ['province_id' => 212, 'province_name' => 'Đồng Nai'],
            ['province_id' => 213, 'province_name' => 'Khánh Hòa'],
            ['province_id' => 214, 'province_name' => 'Lâm Đồng'],
            ['province_id' => 215, 'province_name' => 'Long An'],
            ['province_id' => 216, 'province_name' => 'Nam Định'],
            ['province_id' => 217, 'province_name' => 'Nghệ An'],
            ['province_id' => 218, 'province_name' => 'Quảng Ninh'],
            ['province_id' => 219, 'province_name' => 'Thái Bình'],
            ['province_id' => 220, 'province_name' => 'Thanh Hóa'],
            ['province_id' => 221, 'province_name' => 'Thừa Thiên Huế'],
            ['province_id' => 222, 'province_name' => 'Tiền Giang'],
            ['province_id' => 223, 'province_name' => 'Vĩnh Phúc'],
            ['province_id' => 224, 'province_name' => 'Bắc Kạn'],
            ['province_id' => 225, 'province_name' => 'Bạc Liêu'],
            ['province_id' => 226, 'province_name' => 'Bến Tre'],
            ['province_id' => 227, 'province_name' => 'Bình Định'],
            ['province_id' => 228, 'province_name' => 'Bình Phước'],
            ['province_id' => 229, 'province_name' => 'Cà Mau'],
            ['province_id' => 230, 'province_name' => 'Cao Bằng'],
            ['province_id' => 231, 'province_name' => 'Đắk Lắk'],
            ['province_id' => 232, 'province_name' => 'Đắk Nông'],
            ['province_id' => 233, 'province_name' => 'Điện Biên'],
            ['province_id' => 234, 'province_name' => 'Đồng Tháp'],
            ['province_id' => 235, 'province_name' => 'Gia Lai'],
            ['province_id' => 236, 'province_name' => 'Hà Giang'],
            ['province_id' => 237, 'province_name' => 'Hà Nam'],
            ['province_id' => 238, 'province_name' => 'Hà Tĩnh'],
            ['province_id' => 239, 'province_name' => 'Hải Dương'],
            ['province_id' => 240, 'province_name' => 'Hậu Giang'],
            ['province_id' => 241, 'province_name' => 'Hòa Bình'],
            ['province_id' => 242, 'province_name' => 'Hưng Yên'],
            ['province_id' => 243, 'province_name' => 'Kon Tum'],
            ['province_id' => 244, 'province_name' => 'Lai Châu'],
            ['province_id' => 245, 'province_name' => 'Lạng Sơn'],
            ['province_id' => 246, 'province_name' => 'Lào Cai'],
            ['province_id' => 247, 'province_name' => 'Ninh Bình'],
            ['province_id' => 248, 'province_name' => 'Ninh Thuận'],
            ['province_id' => 249, 'province_name' => 'Phú Thọ'],
            ['province_id' => 250, 'province_name' => 'Phú Yên'],
            ['province_id' => 251, 'province_name' => 'Quảng Bình'],
            ['province_id' => 252, 'province_name' => 'Quảng Nam'],
            ['province_id' => 253, 'province_name' => 'Quảng Ngãi'],
            ['province_id' => 254, 'province_name' => 'Quảng Trị'],
            ['province_id' => 255, 'province_name' => 'Sóc Trăng'],
            ['province_id' => 256, 'province_name' => 'Sơn La'],
            ['province_id' => 257, 'province_name' => 'Tây Ninh'],
            ['province_id' => 258, 'province_name' => 'Thái Nguyên'],
            ['province_id' => 259, 'province_name' => 'Trà Vinh'],
            ['province_id' => 260, 'province_name' => 'Tuyên Quang'],
            ['province_id' => 261, 'province_name' => 'Vĩnh Long'],
            ['province_id' => 262, 'province_name' => 'Yên Bái'],
        ];
    }

    private function mockDistricts($provinceId)
    {
        // Slightly more realistic mock districts
        return [
            ['district_id' => $provinceId * 100 + 1, 'district_name' => 'Quận Trung Tâm'],
            ['district_id' => $provinceId * 100 + 2, 'district_name' => 'Quận Ngoại Thành'],
            ['district_id' => $provinceId * 100 + 3, 'district_name' => 'Huyện Phụ Cận'],
            ['district_id' => $provinceId * 100 + 4, 'district_name' => 'Khu Công Nghiệp'],
        ];
    }

    private function mockWards($districtId)
    {
        return [
            ['ward_code' => (string)($districtId * 10 + 1), 'ward_name' => 'Phường Trung Tâm'],
            ['ward_code' => (string)($districtId * 10 + 2), 'ward_name' => 'Phường Lân Cận'],
            ['ward_code' => (string)($districtId * 10 + 3), 'ward_name' => 'Xã Phụ Cận'],
        ];
    }
}
