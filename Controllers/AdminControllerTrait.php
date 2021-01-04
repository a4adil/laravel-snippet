<?php

namespace App\Http\Controllers;

use App\Account;
use App\Location;
use Artisan;
use Auth;
use DataTables;
use Illuminate\Http\Request;

trait AdminControllerTrait
{

    /**
     * Verifies that the current user has the given permission for the accountId input on the Request, or the passed
     * $accountId, either directly or via the permission on a parent account, and returns the account if the permission
     * is authorized, or null.
     *
     * One of $request or $accountId must be provided
     *
     * @param  string  $permission
     * @param  Request|null  $request
     * @param  string|int|null  $accountId
     * @return Account|null
     */
    private function getAccountForRequest(string $permission, ?Request $request, $accountId = null): ?Account
    {
        if ($accountId == null) {
            $accountId = $request->query('accountId', null);
            if (!$accountId) {
                $accountId = Auth::user()->account_id;
            }
        }

        //verify permission for account by checking account Id and parent tree
        $scopes = scopeResolver(Auth::user(), $permission);
        $accountIds = accountScopeResolver($scopes)->toArray();
        $checkId = $accountId;
        $hasPermission = false;
        if(count($accountIds) > 0) {
            do {
                if (in_array($checkId, $accountIds)) {
                    $hasPermission = true;
                    break;
                }
                $checkId = Account::find($checkId)->parent_account_id;
            } while ($checkId);
        }

        if (!$hasPermission) {
            //See if they have the permission for a location in the account
            $locationIds = locationScopeResolver($scopes)->toArray();
            if(!Location::whereIn('id', $locationIds)->where('account_id', $accountId)->exists()) {
                return null;
            }
        }

        return Account::find($accountId);
    }
}