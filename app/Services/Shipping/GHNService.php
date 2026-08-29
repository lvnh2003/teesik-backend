<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Http;

class GHNService
{
    protected $apiUrl;
    protected $token;
    protected $shopId;

    public function __construct()
    {
        $this->apiUrl = config('services.ghn.url', 'https://dev-online-gateway.ghn.vn/shiip/public-api');
        $this->token = config('services.ghn.token');
        $this->shopId = config('services.ghn.shop_id');
    }

    public function calculateFee(array $data)
    {
        // Example integration
        // Return 30000 flat fee for now as placeholder, pending real token config
        return 30000;
    }
}
