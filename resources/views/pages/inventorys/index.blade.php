@extends('layouts.master')

@section('pageTitle')
    Inventory Items
@endsection

@section('headerBlock')
    <link rel="stylesheet" href="{{ URL::asset('css/main.css') }}">
    <link rel="stylesheet" href="{{ URL::asset('css/delete_form.css') }}">
    <script src="{{ URL::asset('js/main.js') }}" defer></script>
    {{-- SortableJS CDN សម្រាប់មុខងារ Drag & Drop Stat Cards --}}
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script src="{{ URL::asset('js/form.js') }}"></script>
    <script src="{{ URL::asset('js/delete_form.js') }}"></script>
    <style>
        .stock-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            width: fit-content;
        }
        .stock-in  { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
        .stock-low { background: #fef9c3; color: #a16207; border: 1px solid #fde047; }
        .stock-out { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }

        /* Filter bar */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin: 0 0 14px 0;
        }
        .filter-bar .type-filter-wrap .cs-input,
        .filter-bar .type-filter-wrap [class*="select"],
        .filter-bar .type-filter-wrap [class*="dropdown"] > [class*="trigger"],
        .filter-bar .type-filter-wrap [class*="dropdown"] > button,
        .filter-bar .type-filter-wrap [class*="dropdown"] > div:first-child {
            border-radius: 24px !important;
            padding-left: 18px;
        }
        .filter-bar select {
            padding: 8px 14px;
            border: 1px solid #e5e7eb;
            font-size: 13px;
            background: #fff;
            color: #a16207;
            outline: none;
            cursor: pointer;
        }
        .filter-bar select:focus { border-color: #6366f1; }

        /* Wider dropdown so long type names don't wrap */
        .filter-bar .type-filter-wrap {
            width: 320px;
            max-width: 100%;
        }
        .filter-bar label {
            font-size: 17px;
            font-weight: 700;
            color: #a16207;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-bar label i {
            font-size: 17px;
        }
        .filter-bar .type-filter-wrap > *,
        .filter-bar .type-filter-wrap select,
        .filter-bar .type-filter-wrap [class*="select"],
        .filter-bar .type-filter-wrap [class*="dropdown"] {
            width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box;
        }
        .filter-bar .type-filter-wrap [class*="option"],
        .filter-bar .type-filter-wrap li {
            white-space: nowrap;
        }

        .filter-clear {
            font-size: 14px;
            font-weight: 600;
            color: #dc2626;
            background: #fef2f2;
            border: 1px solid #fca5a5;
            border-radius: 24px;
            padding: 9px 18px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: background 0.15s, color 0.15s, transform 0.15s, box-shadow 0.15s;
        }
        .filter-clear:hover {
            background: #dc2626;
            color: #fff;
            text-decoration: none;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(220, 38, 38, 0.25);
        }
        .filter-clear:active {
            transform: translateY(0);
        }

        /* Styles សម្រាប់ Stat Cards Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            cursor: default;
        }

        .stat-card {
            cursor: grab !important;
            user-select: none;
            transition: transform 0.22s ease, box-shadow 0.22s ease;
            word-break: break-word;
        }
        .stat-card:active {
            cursor: grabbing !important;
        }
        .sortable-ghost {
            opacity: 0.3;
            background: #f0f0f0;
            border: 2px dashed #030304;
        }
        .sortable-drag {
            background: #ffffff;
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Pagination */
        .inventory-pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        /* ── Mobile 2-Column Rules ── */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 12px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 10px;
            }

            .stat-card {
                padding: 12px 10px;
            }

            .stat-card h3 {
                font-size: 1.2rem;
                margin-bottom: 4px;
            }

            .stat-card p {
                font-size: 11px;
            }

            .filter-bar .type-filter-wrap { flex: 1; }
        }
    </style>
@endsection

@section('content')
    @if(session('success'))
        <div id="successMessage" class="custom-success">
            <div class="success-content">
                <span class="success-icon">✔</span>
                <span class="success-text">{{ session('success') }}</span>
            </div>
            <div class="progress-bar"></div>
        </div>
    @endif

    <div class="content-section" id="inventory-items">
        <h2><i class="fas fa-boxes"></i> Inventory Items</h2>

        <div style="margin: 16px 0 12px 0;">
            <a href="{{ route('inventory-items.create') }}" class="btn btn-primary page-link-loading" data-loading-text="Loading add...">
                <i class="fas fa-circle-plus"></i> Add New Items
            </a>
        </div>

        {{-- Stats Grid ដាក់ id="sortableCards" ដើម្បីឱ្យអាចទាញប្ដូរទីតាំងឆ្វេងស្តាំបាន --}}
        <div class="stats-grid" id="sortableCards">
            <div class="stat-card">
                <p>Total Items</p>
                <h3>{{ $totalItems }}</h3>
            </div>
            <div class="stat-card">
                <p>Low Stock Products</p>
                <h3>{{ $lowStockItems }}</h3>
            </div>
            <div class="stat-card">
                <p>Out of Stocks</p>
                <h3>{{ $outOfStockItems }}</h3>
            </div>
            <div class="stat-card">
                <p>Inventory Value</p>
                <h3>${{ number_format($inventoryValue, 2) }}</h3>
            </div>
        </div>

        {{-- Filter by type --}}
        <div class="filter-bar" style="margin-top: 16px;">
            <label for="typeFilter"><i class="fas fa-layer-group"></i> Filter by Type:</label>
            <div class="type-filter-wrap">
                <select id="typeFilter" class="js-select" data-current="{{ $selectedType }}">
                    <option value="">All types</option>
                    @foreach($types as $type)
                        <option value="{{ $type->name }}" {{ $selectedType === $type->name ? 'selected' : '' }}>
                            {{ $type->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            @if($selectedType)
                <a href="{{ route('inventory-items.index') }}" class="filter-clear page-link-loading" data-loading-text="Clearing...">
                    <i class="fas fa-times-circle"></i> Clear filter
                </a>
            @endif
        </div>

        {{-- Table --}}
        <div class="table-container" role="region" aria-label="Inventory items table" style="margin-top: 20px;">
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Qty</th>
                    <th>Total Price</th>
                    <th>Type</th>
                    <th>Unit</th>
                    <th>Status</th>
{{--                    <th>Last Updated</th>--}}
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td data-label="No">#{{ $loop->iteration + ($items->currentPage() - 1) * $items->perPage() }}</td>
                        <td data-label="Name" style="color: black; font-weight: 600;">{{ $item->name }}</td>
                        <td data-label="Qty" style="color: black; font-weight: 600;">{{ $item->qty }}</td>
                        <td data-label="Price" style="color: #2e7d32; font-weight: 600;">${{ number_format($item->price, 2) }}</td>
                        <td data-label="Type">{{ $item->type ?? '-' }}</td>
                        <td data-label="Unit">{{ $item->unit ?? '-' }}</td>
                        <td data-label="Status">
                            @if($item->status === 'out')
                                <span class="stock-badge stock-out">Out of Stock</span>
                            @elseif($item->status === 'low')
                                <span class="stock-badge stock-low">Low Stock</span>
                            @else
                                <span class="stock-badge stock-in">In Stock</span>
                            @endif
                        </td>
{{--                        <td data-label="Updated Date">{{ $item->updated_at->timezone('Asia/Phnom_Penh')->format('d M, Y h:i A') }}</td>--}}
                        <td data-label="Actions">
                            <div class="action-buttons">
                                <a href="{{ route('inventory-items.show', $item->id) }}"
                                   class="action-btn show-btn page-link-loading"
                                   data-loading-text="Loading details..."
                                   title="View Details">
                                    <i class="fas fa-info-circle"></i>
                                </a>
                                <a href="{{ route('inventory-items.edit', $item->id) }}"
                                   class="action-btn edit-btn page-link-loading"
                                   data-loading-text="Opening editor..."
                                   title="Edit item">
                                    <i class="fas fa-pen"></i>
                                </a>
                                <button type="button"
                                        class="action-btn delete-btn openDeleteModal"
                                        data-action="{{ route('inventory-items.destroy', $item->id) }}"
                                        title="Delete item">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align:center;" id="found">
                            @if($selectedType)
                                No items found for type "{{ $selectedType }}".
                            @else
                                No items found.
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination (keeps ?type=... via withQueryString) --}}
        @if ($items->hasPages())
            <nav aria-label="Page navigation" class="inventory-pagination">
                <ul class="pagination-list">

                    {{-- Previous --}}
                    @if ($items->onFirstPage())
                        <li class="page-btn disabled"><span> <i class="fa fa-angle-left"></i></span></li>
                    @else
                        <li class="page-btn">
                            <a href="{{ $items->previousPageUrl() }}" class="page-link-loading" data-loading-text="Loading...">
                                <i class="fa fa-angle-left"></i>
                            </a>
                        </li>
                    @endif

                    {{-- Page numbers --}}
                    @foreach ($items->getUrlRange(1, $items->lastPage()) as $page => $url)
                        @if ($page == $items->currentPage())
                            <li class="page-btn active"><span>{{ $page }}</span></li>
                        @else
                            <li class="page-btn">
                                <a href="{{ $url }}" class="page-link-loading" data-loading-text="Loading...">{{ $page }}</a>
                            </li>
                        @endif
                    @endforeach

                    {{-- Next --}}
                    @if ($items->hasMorePages())
                        <li class="page-btn">
                            <a href="{{ $items->nextPageUrl() }}" class="page-link-loading" data-loading-text="Loading...">
                                <i class="fa fa-angle-right"></i>
                            </a>
                        </li>
                    @else
                        <li class="page-btn disabled"><span> <i class="fa fa-angle-right"></i></span></li>
                    @endif

                </ul>
            </nav>
        @endif
    </div>

    {{-- Delete Confirmation Modal --}}
    <x-delete-modal />

    <script>
        // ===== Enable Drag & Drop for Stat Cards =====
        document.addEventListener('DOMContentLoaded', () => {
            const el = document.getElementById('sortableCards');
            if (el) {
                new Sortable(el, {
                    animation: 200,
                    ghostClass: 'sortable-ghost',
                    dragClass: 'sortable-drag',
                    onStart: function (evt) {
                        evt.item.style.cursor = 'grabbing';
                    },
                    onEnd: function (evt) {
                        evt.item.style.cursor = 'grab';
                    }
                });
            }
        });

        function showLoading(msg) {
            const ov = document.getElementById('loading-overlay');
            const lt = document.getElementById('loading-text');
            if (!ov) return;
            if (lt) lt.textContent = msg || 'Loading...';
            ov.style.display = 'flex';
        }

        document.querySelectorAll('.page-link-loading').forEach(function(link) {
            link.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                const msg  = this.getAttribute('data-loading-text') || 'Loading...';
                if (href && href !== '#' && href !== 'javascript:void(0)') {
                    e.preventDefault();
                    showLoading(msg);
                    window.location.href = href;
                }
            });
        });

        // ===== Filter by type (works with custom js-select) =====
        (function () {
            const select = document.getElementById('typeFilter');
            if (!select) return;

            const baseUrl = "{{ route('inventory-items.index') }}";
            let navigating = false;

            function applyTypeFilter() {
                if (navigating) return;
                if (select.value === (select.dataset.current || '')) return; // no change
                navigating = true;

                const url = new URL(baseUrl, window.location.origin);
                if (select.value) url.searchParams.set('type', select.value);

                showLoading('Filtering...');
                window.location.href = url.toString();
            }

            // Native + jQuery (Select2) events
            ['change', 'input'].forEach(evt => select.addEventListener(evt, applyTypeFilter));
            if (window.jQuery) window.jQuery(select).on('change select2:select', applyTypeFilter);

            // Fallback for custom dropdowns that set .value without firing events
            setInterval(applyTypeFilter, 250);
        })();
    </script>

@endsection