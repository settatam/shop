<?php

namespace App\Http\Controllers;

use App\Services\StoreContext;
use App\Widget\Form;
use App\Widget\Widget;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WidgetsController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Validate and instantiate the widget class from the request.
     *
     * @throws ValidationException
     */
    protected function validateRequest(Request $request, string $action = 'view'): Widget
    {
        $data = $request->validate([
            'type' => 'required|string',
        ]);

        $class = $data['type'];
        $namespace = 'App\\Widget\\';

        if (! Str::startsWith($class, $namespace)) {
            $class = $namespace.$class;
        }

        if (! class_exists($class)) {
            abort(400, "Widget class {$class} does not exist.");
        }

        $widget = new $class;

        if (! $widget instanceof Widget) {
            abort(400, 'Invalid widget class.');
        }

        return $widget;
    }

    /**
     * View widget data.
     *
     * @throws ValidationException
     */
    public function view(Request $request): JsonResponse
    {
        $filters = $request->input();

        // Support base64 encoded data
        if ($base64Data = data_get($request->input(), 'base64data')) {
            $filters = json_decode(base64_decode($base64Data), true);
        }

        // Normalize date filters
        if ($from = data_get($filters, 'from')) {
            $filters['dates']['from'] = $from;
        }

        if ($to = data_get($filters, 'to')) {
            $filters['dates']['to'] = $to;
        }

        // Add store_id to filters
        $filters['store_id'] = $this->storeContext->getCurrentStore()?->id;

        return response()->json(
            $this->validateRequest($request, 'view')->render($filters)
        );
    }

    /**
     * Process a form widget.
     */
    public function process(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|string',
        ]);

        $class = $data['type'];
        $namespace = 'App\\Widget\\';

        if (! Str::startsWith($class, $namespace)) {
            $class = $namespace.$class;
        }

        if (! class_exists($class)) {
            abort(400, "Form class {$class} does not exist.");
        }

        $form = new $class;

        if (! $form instanceof Form) {
            abort(400, 'Invalid form class.');
        }

        return response()->json($form->process($request, $id));
    }

    /**
     * Export widget data.
     */
    public function export(Request $request, ?int $id = null): BinaryFileResponse
    {
        $input = $request->input();
        $filter = [];

        // Parse items from JSON
        if ($items = data_get($input, 'items')) {
            data_set($filter, 'items', json_decode($items, true));
            unset($input['items']);
        }

        // Parse filter from JSON
        if ($pageFilter = data_get($input, 'filter')) {
            $filter = array_merge($filter, json_decode($pageFilter, true));
            unset($input['filter']);
        }

        // Merge remaining input
        foreach ($input as $index => $value) {
            $filter[$index] = $value;
        }

        // Add store_id to filters
        $filter['store_id'] = $this->storeContext->getCurrentStore()?->id;

        $title = data_get($input, 'title', 'untitled');
        $filename = sprintf('%s-%s.csv', $title, Carbon::now()->format('m-d-Y'));

        $widget = $request->input('widget');

        if (! $widget || ! class_exists($widget)) {
            abort(400, 'Invalid export widget class.');
        }

        return ExcelFacade::download(new $widget($filter), $filename, Excel::CSV);
    }
}
