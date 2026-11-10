<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountDataController extends Controller
{
    /** @var list<string> */
    private const PORTFOLIO_TABLES = [
        'portfolio_holdings', 'portfolio_transactions', 'cash_transactions',
        'cash_placements', 'portfolio_analyses', 'buy_recommendation_runs',
        'portfolio_imports', 'cash_statement_imports', 'cash_statement_entries',
    ];

    public function export(Request $request): JsonResponse
    {
        $user = $this->confirmedUser($request);

        $data = DB::transaction(function () use ($user): array {
            $portfolios = DB::table('portfolios')->where('user_id', $user->id)->orderBy('id')->get();
            $ids = $portfolios->pluck('id')->all();
            $records = [];
            foreach (self::PORTFOLIO_TABLES as $table) {
                $records[$table] = DB::table($table)->whereIn('portfolio_id', $ids)->orderBy('id')->get();
            }
            $runs = DB::table('buy_recommendation_runs')->whereIn('portfolio_id', $ids)->pluck('id');
            $records['buy_recommendations'] = DB::table('buy_recommendations')->whereIn('run_id', $runs)->orderBy('id')->get();

            return [
                'format_version' => 1,
                'exported_at' => now()->toIso8601String(),
                'account' => $user->only([
                    'id', 'name', 'email', 'created_at', 'updated_at',
                    'benchmark_fd_rate', 'benchmark_index_slug', 'investor_class',
                ]),
                'portfolios' => $portfolios,
                'records' => $records,
            ];
        });

        return response()->json($data)
            ->header('Content-Disposition', 'attachment; filename="nepse-account-export.json"')
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['confirmation' => ['required', Rule::in(['DELETE'])]]);
        $user = $this->confirmedUser($request);

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table(config('auth.passwords.users.table'))->where('email', $user->email)->delete();
            // Foreign keys cascade through portfolios, ledgers, imports, and saved analyses.
            $user->delete();
        });

        return response()->json(['message' => 'Your account and portfolio data have been deleted.'])
            ->header('Cache-Control', 'no-store, private');
    }

    private function confirmedUser(Request $request): User
    {
        $validated = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Your current password is incorrect.']);
        }

        return $user;
    }
}
