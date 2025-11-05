document.addEventListener('DOMContentLoaded', function () {
    var tableElement = document.getElementById('hp-products-table');
    if (!tableElement || typeof Tabulator === 'undefined') {
        return;
    }

    var sampleData = [
        {
            id: 1,
            image: 'https://via.placeholder.com/40x40.png?text=HP',
            name: 'BrainON Blue Green Algae Extract',
            sku: 'BEA-90',
            cost: 18.75,
            price: 42.95,
            brand: 'E3Live',
            stock: 40,
            stock_detail: 'Wilton (new): 6 / 6',
            status: 'Enabled',
            visibility: 'Catalog, Search'
        },
        {
            id: 2,
            image: 'https://via.placeholder.com/40x40.png?text=PE',
            name: 'Inositol (powder) 250g',
            sku: 'PE-INP2',
            cost: 23.37,
            price: 50.20,
            brand: 'Pure Encapsulations',
            stock: 15,
            stock_detail: 'Wilton (new): 1 / 1',
            status: 'Enabled',
            visibility: 'Catalog, Search'
        },
        {
            id: 3,
            image: 'https://via.placeholder.com/40x40.png?text=LI',
            name: 'Huperzine A 60 caps',
            sku: 'LEF01527',
            cost: 20.00,
            price: 30.00,
            brand: 'Life Extension',
            stock: 0,
            stock_detail: 'Wilton (new): 0 / 0',
            status: 'Disabled',
            visibility: 'Not Visible Individually'
        }
    ];

    var columns = [
        {
            formatter: 'rowSelection',
            titleFormatter: 'rowSelection',
            hozAlign: 'center',
            vertAlign: 'middle',
            width: 40,
            headerSort: false
        },
        {
            title: '',
            field: 'image',
            width: 60,
            hozAlign: 'center',
            formatter: function (cell) {
                var url = cell.getValue();
                return '<img src="' + url + '" alt="" class="hp-pm-thumb">';
            },
            headerSort: false
        },
        { title: 'Name', field: 'name', minWidth: 250, formatter: 'textarea' },
        { title: 'SKU', field: 'sku', width: 140 },
        { title: 'Cost', field: 'cost', width: 110, hozAlign: 'right', formatter: 'money', formatterParams: { symbol: '$', precision: 2 } },
        { title: 'Price', field: 'price', width: 110, hozAlign: 'right', formatter: 'money', formatterParams: { symbol: '$', precision: 2 } },
        { title: 'Brand', field: 'brand', width: 170 },
        {
            title: 'Stock',
            field: 'stock',
            width: 130,
            formatter: function (cell) {
                var value = cell.getValue();
                var detail = cell.getRow().getData().stock_detail;
                var klass = value > 0 ? 'hp-pm-stock-ok' : 'hp-pm-stock-low';
                return '<span class="' + klass + '">' + value + '</span><div class="hp-pm-stock-detail">' + detail + '</div>';
            }
        },
        { title: 'Status', field: 'status', width: 130 },
        {
            title: 'Visibility',
            field: 'visibility',
            minWidth: 160,
            formatter: function (cell) {
                var text = cell.getValue();
                if (text.toLowerCase().indexOf('not visible') !== -1) {
                    return '<span class="hp-pm-visibility-hidden">' + text + '</span>';
                }
                return text;
            }
        }
    ];

    new Tabulator(tableElement, {
        data: sampleData,
        layout: 'fitColumns',
        reactiveData: true,
        height: '620px',
        selectable: true,
        columns: columns,
        initialSort: [
            { column: 'stock', dir: 'asc' }
        ],
        placeholder: 'Loading products…'
    });
});
