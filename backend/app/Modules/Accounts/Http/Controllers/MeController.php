<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounts\Actions\DeleteAccount;
use App\Modules\Accounts\Actions\UpdatePreferences;
use App\Modules\Accounts\Http\Requests\DeleteMeRequest;
use App\Modules\Accounts\Http\Requests\UpdateMeRequest;
use App\Modules\Accounts\Http\Resources\MeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class MeController extends Controller
{
    public function show(Request $request): MeResource
    {
        return new MeResource($this->user($request));
    }

    public function update(UpdateMeRequest $request, UpdatePreferences $updatePreferences): MeResource
    {
        /** @var array{name?: string, locale?: string, timezone?: string, country?: string|null} $data */
        $data = $request->validated();

        return new MeResource($updatePreferences->handle($this->user($request), $data));
    }

    public function destroy(DeleteMeRequest $request, DeleteAccount $deleteAccount): Response
    {
        $deleteAccount->handle($this->user($request));

        // logoutCurrentDevice() ne réenregistre pas le jeton "remember me" : logout() le ferait
        // et recréerait l'utilisateur qui vient d'être supprimé.
        Auth::guard('web')->logoutCurrentDevice();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
