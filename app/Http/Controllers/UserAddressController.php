<?php

namespace App\Http\Controllers;

use App\Models\UserAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserAddressController extends Controller
{
    public function index()
    {
        $addresses = UserAddress::where('user_id', Auth::id())
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
            
        return $this->successResponse($addresses);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'receiver_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'province_id' => 'required|integer',
            'province' => 'required|string',
            'district_id' => 'required|integer',
            'district' => 'required|string',
            'ward_code' => 'required|string',
            'ward' => 'required|string',
            'specific_address' => 'required|string',
        ]);

        $isDefault = $request->boolean('is_default', false);
        $userId = Auth::id();

        // If it's the first address or set to default, reset others
        if ($isDefault || UserAddress::where('user_id', $userId)->count() === 0) {
            $isDefault = true;
            UserAddress::where('user_id', $userId)->update(['is_default' => false]);
        }

        $validated['is_default'] = $isDefault;
        $validated['user_id'] = $userId;

        $address = UserAddress::create($validated);

        return $this->successResponse($address, 'Thêm địa chỉ thành công');
    }

    public function update(Request $request, $id)
    {
        $address = UserAddress::where('user_id', Auth::id())->findOrFail($id);

        $validated = $request->validate([
            'receiver_name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:20',
            'province_id' => 'sometimes|required|integer',
            'province' => 'sometimes|required|string',
            'district_id' => 'sometimes|required|integer',
            'district' => 'sometimes|required|string',
            'ward_code' => 'sometimes|required|string',
            'ward' => 'sometimes|required|string',
            'specific_address' => 'sometimes|required|string',
        ]);

        if ($request->has('is_default')) {
            $isDefault = $request->boolean('is_default');
            if ($isDefault) {
                UserAddress::where('user_id', Auth::id())->update(['is_default' => false]);
            }
            $validated['is_default'] = $isDefault;
        }

        $address->update($validated);

        return $this->successResponse($address, 'Cập nhật địa chỉ thành công');
    }

    public function destroy($id)
    {
        $address = UserAddress::where('user_id', Auth::id())->findOrFail($id);
        
        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $latest = UserAddress::where('user_id', Auth::id())->latest()->first();
            if ($latest) {
                $latest->update(['is_default' => true]);
            }
        }

        return $this->successResponse(null, 'Đã xóa địa chỉ');
    }

    public function setDefault($id)
    {
        $address = UserAddress::where('user_id', Auth::id())->findOrFail($id);

        UserAddress::where('user_id', Auth::id())->update(['is_default' => false]);
        $address->update(['is_default' => true]);

        return $this->successResponse($address, 'Đã thiết lập địa chỉ mặc định');
    }
}
