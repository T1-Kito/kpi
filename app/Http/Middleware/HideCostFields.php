<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HideCostFields
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($response instanceof JsonResponse && $request->user() && ! $request->user()->hasPermission('master.cost.view')) {
            $response->setData($this->redact($response->getData(true)));
        }
        return $response;
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['cost_price', 'unit_cost', 'line_cost', 'total_cost', 'margin_percent'], true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }
        return $data;
    }
}
