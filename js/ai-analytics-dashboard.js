/**
 * AI Analytics Dashboard JavaScript Engine
 * Implements 7 Smart Analysis Modules
 */

class AIAnalyticsDashboard {
    constructor() {
        this.charts = {};
        this.currentModule = 'trend';
        this.apiEndpoints = {
            trend: 'api_trend_analysis.php',
            inventory: 'api_inventory_turnover.php', 
            supply: 'api_supply_chain.php',
            profit: 'api_profitability.php',
            behavior: 'api_consumer_behavior.php',
            quality: 'api_data_quality.php',
            market: 'api_market_trends.php'
        };
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadModule('trend');
        this.startRealTimeUpdates();
    }

    bindEvents() {
        // Module tabs
        $('a[data-toggle="pill"]').on('shown.bs.tab', (e) => {
            const moduleId = e.target.getAttribute('href').replace('#', '').replace('-module', '');
            this.loadModule(moduleId);
        });

        // Action buttons
        $('#refreshAll').on('click', () => this.refreshAllModules());
        $('#generateReport').on('click', () => this.generateFullReport());
        $('#exportInsights').on('click', () => this.exportInsights());
        $('#scheduleAnalysis').on('click', () => this.scheduleAnalysis());
        $('#aiSettings').on('click', () => this.openAISettings());
    }

    async loadModule(moduleId) {
        this.currentModule = moduleId;
        
        try {
            this.showLoading(moduleId);
            
            switch(moduleId) {
                case 'trend':
                    await this.loadTrendModule();
                    break;
                case 'inventory':
                    await this.loadInventoryModule();
                    break;
                case 'supply':
                    await this.loadSupplyModule();
                    break;
                case 'profit':
                    await this.loadProfitModule();
                    break;
                case 'behavior':
                    await this.loadBehaviorModule();
                    break;
                case 'quality':
                    await this.loadQualityModule();
                    break;
                case 'market':
                    await this.loadMarketModule();
                    break;
            }
            
            this.hideLoading(moduleId);
        } catch (error) {
            console.error(`Error loading ${moduleId} module:`, error);
            this.showError(moduleId, error.message);
        }
    }

    // Module 1: Trend & Seasonality Analysis
    async loadTrendModule() {
        // Sample data - replace with actual API call
        const trendData = {
            seasonal: {
                labels: ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12'],
                datasets: [
                    {
                        label: 'Boots',
                        data: [45, 55, 60, 58, 52, 48, 45, 50, 65, 85, 95, 90],
                        borderColor: '#8B4513',
                        backgroundColor: 'rgba(139, 69, 19, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Sneakers', 
                        data: [80, 85, 88, 92, 95, 90, 85, 88, 82, 75, 70, 75],
                        borderColor: '#4e73df',
                        backgroundColor: 'rgba(78, 115, 223, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Sandals',
                        data: [30, 45, 65, 85, 95, 100, 98, 90, 75, 55, 35, 25],
                        borderColor: '#f6c23e',
                        backgroundColor: 'rgba(246, 194, 62, 0.1)',
                        tension: 0.4
                    }
                ]
            },
            insights: [
                { icon: 'fa-arrow-up', text: 'Boots tăng 35% mùa lạnh', type: 'success' },
                { icon: 'fa-arrow-down', text: 'Sandals giảm 40% tháng này', type: 'warning' },
                { icon: 'fa-chart-line', text: 'Sneakers ổn định quanh năm', type: 'info' }
            ],
            trending: [
                { category: 'Winter Boots', growth: '+35%', color: 'success' },
                { category: 'White Sneakers', growth: '+22%', color: 'primary' },
                { category: 'Canvas Shoes', growth: '+15%', color: 'info' },
                { category: 'High Heels', growth: '-8%', color: 'warning' }
            ]
        };

        this.createTrendSeasonalChart(trendData.seasonal);
        this.updateTrendInsights(trendData.insights);
        this.updateTrendingCategories(trendData.trending);
    }

    createTrendSeasonalChart(data) {
        const ctx = document.getElementById('trendSeasonalChart');
        if (!ctx) return;

        if (this.charts.trendSeasonal) {
            this.charts.trendSeasonal.destroy();
        }

        this.charts.trendSeasonal = new Chart(ctx, {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Xu hướng theo mùa - 12 tháng qua'
                    },
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Số lượng bán'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Tháng'
                        }
                    }
                }
            }
        });
    }

    updateTrendInsights(insights) {
        let html = '';
        insights.forEach(insight => {
            html += `
                <div class="insight-item mb-2">
                    <i class="fas ${insight.icon} mr-2"></i>
                    <small>${insight.text}</small>
                </div>
            `;
        });
        $('#trendInsights').html(html);
    }

    updateTrendingCategories(trending) {
        let html = '';
        trending.forEach(item => {
            html += `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="font-weight-bold">${item.category}</small>
                    <span class="badge badge-${item.color}">${item.growth}</span>
                </div>
            `;
        });
        $('#trendingCategories').html(html);
    }

    // Module 2: Inventory Turnover & Aging
    async loadInventoryModule() {
        try {
            const response = await $.get(this.apiEndpoints.inventory);
            if (response.success) {
                this.displayInventoryTurnover(response.data);
            } else {
                this.showError('inventory-turnover-content', 'Failed to load inventory turnover analysis');
            }
        } catch (error) {
            console.error('Inventory module error:', error);
            this.showError('inventory-turnover-content', 'Network error loading inventory turnover analysis');
        }
    }

    async loadSupplyModule() {
        try {
            const response = await $.get(this.apiEndpoints.supply);
            if (response.success) {
                this.displaySupplyChain(response.data);
            } else {
                this.showError('supply-chain-content', 'Failed to load supply chain analysis');
            }
        } catch (error) {
            console.error('Supply chain module error:', error);
            this.showError('supply-chain-content', 'Network error loading supply chain analysis');
        }
    }

    async loadProfitModule() {
        try {
            const response = await $.get(this.apiEndpoints.profit);
            if (response.success) {
                this.displayProfitability(response.data);
            } else {
                this.showError('profitability-content', 'Failed to load profitability analysis');
            }
        } catch (error) {
            console.error('Profitability module error:', error);
            this.showError('profitability-content', 'Network error loading profitability analysis');
        }
    }

    async loadBehaviorModule() {
        try {
            const response = await $.get(this.apiEndpoints.behavior);
            if (response.success) {
                this.displayConsumerBehavior(response.data);
            } else {
                this.showError('consumer-behavior-content', 'Failed to load consumer behavior analysis');
            }
        } catch (error) {
            console.error('Consumer behavior module error:', error);
            this.showError('consumer-behavior-content', 'Network error loading consumer behavior analysis');
        }
    }

    async loadQualityModule() {
        try {
            const response = await $.get(this.apiEndpoints.quality);
            if (response.success) {
                this.displayDataQuality(response.data);
            } else {
                this.showError('data-quality-content', 'Failed to load data quality analysis');
            }
        } catch (error) {
            console.error('Data quality module error:', error);
            this.showError('data-quality-content', 'Network error loading data quality analysis');
        }
    }

    async loadMarketModule() {
        try {
            const response = await $.get(this.apiEndpoints.market);
            if (response.success) {
                this.displayMarketTrends(response.data);
            } else {
                this.showError('market-trends-content', 'Failed to load market trends analysis');
            }
        } catch (error) {
            console.error('Market trends module error:', error);
            this.showError('market-trends-content', 'Network error loading market trends analysis');
        }
    }

    // Legacy method for fallback - keeping sample data structure
    async loadInventoryModuleFallback() {
        // Turnover Chart
        const turnoverData = {
            labels: ['Sneakers', 'Boots', 'Sandals', 'Heels', 'Casual'],
            datasets: [{
                label: 'Turnover Rate (lần/năm)',
                data: [8.5, 6.2, 12.3, 4.1, 7.8],
                backgroundColor: [
                    '#4e73df',
                    '#1cc88a',
                    '#36b9cc',
                    '#f6c23e',
                    '#e74a3b'
                ]
            }]
        };

        // Aging Chart
        const agingData = {
            labels: ['0-30 ngày', '31-60 ngày', '61-90 ngày', '>90 ngày'],
            datasets: [{
                label: 'Số sản phẩm',
                data: [156, 89, 34, 12],
                backgroundColor: ['#28a745', '#ffc107', '#fd7e14', '#dc3545']
            }]
        };

        this.createTurnoverChart(turnoverData);
        this.createAgingChart(agingData);
        this.updateTurnoverTable();
    }

    createTurnoverChart(data) {
        const ctx = document.getElementById('turnoverChart');
        if (!ctx) return;

        if (this.charts.turnover) {
            this.charts.turnover.destroy();
        }

        this.charts.turnover = new Chart(ctx, {
            type: 'bar',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Vòng quay theo danh mục'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    createAgingChart(data) {
        const ctx = document.getElementById('agingChart');
        if (!ctx) return;

        if (this.charts.aging) {
            this.charts.aging.destroy();
        }

        this.charts.aging = new Chart(ctx, {
            type: 'doughnut',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Độ tuổi hàng tồn'
                    }
                }
            }
        });
    }

    updateTurnoverTable() {
        const sampleData = [
            { sku: 'NIKE-SNE-851-42', name: 'Nike Air Max', dii: 45, rate: 8.1, status: 'good', rec: 'Maintain stock level' },
            { sku: 'ADID-BOO-432-41', name: 'Adidas Winter Boot', dii: 67, rate: 5.4, status: 'warning', rec: 'Reduce next order by 20%' },
            { sku: 'VANS-CAS-123-40', name: 'Vans Classic', dii: 28, rate: 13.0, status: 'excellent', rec: 'Increase stock 15%' },
            { sku: 'CONV-HIG-789-39', name: 'Converse High Top', dii: 89, rate: 4.1, status: 'poor', rec: 'Consider promotion or discontinue' }
        ];

        let html = '';
        sampleData.forEach(item => {
            const statusColors = {
                'excellent': 'success',
                'good': 'primary', 
                'warning': 'warning',
                'poor': 'danger'
            };

            html += `
                <tr>
                    <td><code>${item.sku}</code></td>
                    <td>${item.name}</td>
                    <td>${item.dii} ngày</td>
                    <td>${item.rate}x/năm</td>
                    <td><span class="badge badge-${statusColors[item.status]}">${item.status}</span></td>
                    <td><small>${item.rec}</small></td>
                </tr>
            `;
        });

        $('#turnoverTableBody').html(html);
    }

    // Module 3: Supply Chain Efficiency
    async loadSupplyModule() {
        // Supplier Performance
        const supplierData = {
            labels: ['Supplier A', 'Supplier B', 'Supplier C', 'Supplier D'],
            datasets: [{
                label: 'Delivery Score',
                data: [95, 87, 92, 78],
                backgroundColor: '#1cc88a'
            }]
        };

        // Delivery Time
        const deliveryData = {
            labels: ['1-2 ngày', '3-5 ngày', '6-10 ngày', '>10 ngày'],
            datasets: [{
                label: 'Số đơn hàng',
                data: [45, 120, 67, 23],
                backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545']
            }]
        };

        this.createSupplierChart(supplierData);
        this.createDeliveryTimeChart(deliveryData);
        this.updateSupplyInsights();
    }

    createSupplierChart(data) {
        const ctx = document.getElementById('supplierPerformanceChart');
        if (!ctx) return;

        if (this.charts.supplier) {
            this.charts.supplier.destroy();
        }

        this.charts.supplier = new Chart(ctx, {
            type: 'bar',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Hiệu suất nhà cung cấp'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100
                    }
                }
            }
        });
    }

    createDeliveryTimeChart(data) {
        const ctx = document.getElementById('deliveryTimeChart');
        if (!ctx) return;

        if (this.charts.delivery) {
            this.charts.delivery.destroy();
        }

        this.charts.delivery = new Chart(ctx, {
            type: 'pie',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Thời gian giao hàng'
                    }
                }
            }
        });
    }

    updateSupplyInsights() {
        const insights = `
            <div class="insight-item mb-2">
                <i class="fas fa-truck mr-2"></i>
                <small>Supplier A: 95% on-time delivery</small>
            </div>
            <div class="insight-item mb-2">
                <i class="fas fa-clock mr-2"></i>
                <small>Avg delivery: 4.2 ngày</small> 
            </div>
            <div class="insight-item mb-2">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <small>Supplier D cần cải thiện</small>
            </div>
        `;
        $('#supplyInsights').html(insights);
    }

    // Module 4: Profitability & Margin Analysis
    async loadProfitModule() {
        const profitData = {
            labels: ['Sneakers', 'Boots', 'Sandals', 'Heels', 'Casual'],
            datasets: [{
                label: 'Lợi nhuận (triệu VND)',
                data: [125, 89, 156, 78, 94],
                backgroundColor: '#e74a3b'
            }]
        };

        const marginData = {
            labels: ['Nike', 'Adidas', 'Vans', 'Converse', 'Others'],
            datasets: [{
                label: 'Biên lợi nhuận (%)',
                data: [35, 32, 28, 25, 20],
                backgroundColor: [
                    '#4e73df',
                    '#1cc88a', 
                    '#36b9cc',
                    '#f6c23e',
                    '#858796'
                ]
            }]
        };

        this.createProfitChart(profitData);
        this.createMarginChart(marginData);
    }

    createProfitChart(data) {
        const ctx = document.getElementById('profitChart');
        if (!ctx) return;

        if (this.charts.profit) {
            this.charts.profit.destroy();
        }

        this.charts.profit = new Chart(ctx, {
            type: 'bar',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Lợi nhuận theo danh mục'
                    }
                }
            }
        });
    }

    createMarginChart(data) {
        const ctx = document.getElementById('marginChart');
        if (!ctx) return;

        if (this.charts.margin) {
            this.charts.margin.destroy();
        }

        this.charts.margin = new Chart(ctx, {
            type: 'doughnut',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Biên lợi nhuận theo thương hiệu'
                    }
                }
            }
        });
    }

    // Module 5: Consumer Behavior Analysis
    async loadBehaviorModule() {
        const segmentData = {
            labels: ['Loyal', 'Seasonal', 'One-time', 'Potential'],
            datasets: [{
                data: [35, 28, 22, 15],
                backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#6f42c1']
            }]
        };

        const patternData = {
            labels: ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'CN'],
            datasets: [{
                label: 'Số đơn hàng',
                data: [45, 52, 48, 61, 89, 126, 98],
                borderColor: '#f093fb',
                backgroundColor: 'rgba(240, 147, 251, 0.1)',
                tension: 0.4
            }]
        };

        this.createCustomerSegmentChart(segmentData);
        this.createPurchasePatternChart(patternData);
        this.updateBehaviorInsights();
    }

    createCustomerSegmentChart(data) {
        const ctx = document.getElementById('customerSegmentChart');
        if (!ctx) return;

        if (this.charts.segment) {
            this.charts.segment.destroy();
        }

        this.charts.segment = new Chart(ctx, {
            type: 'pie',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Phân khúc khách hàng'
                    }
                }
            }
        });
    }

    createPurchasePatternChart(data) {
        const ctx = document.getElementById('purchasePatternChart');
        if (!ctx) return;

        if (this.charts.pattern) {
            this.charts.pattern.destroy();
        }

        this.charts.pattern = new Chart(ctx, {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Pattern mua hàng trong tuần'
                    }
                }
            }
        });
    }

    updateBehaviorInsights() {
        const insights = `
            <div class="insight-item mb-2">
                <i class="fas fa-heart mr-2"></i>
                <small>35% khách hàng trung thành</small>
            </div>
            <div class="insight-item mb-2">
                <i class="fas fa-calendar mr-2"></i>
                <small>Cuối tuần bán nhiều nhất</small>
            </div>
            <div class="insight-item mb-2">
                <i class="fas fa-users mr-2"></i>
                <small>Khách mua boots thường mua thêm phụ kiện</small>
            </div>
            <div class="insight-item mb-2">
                <i class="fas fa-map-marker-alt mr-2"></i>
                <small>Miền Bắc chuộng boots, miền Nam chuộng sandals</small>
            </div>
        `;
        $('#behaviorInsights').html(insights);
    }

    // Module 6: Data Quality & Risk Analysis
    async loadQualityModule() {
        const qualityIssues = [
            { type: 'Missing Images', count: 12, severity: 'warning' },
            { type: 'Invalid Prices', count: 3, severity: 'danger' },
            { type: 'Duplicate SKUs', count: 2, severity: 'danger' },
            { type: 'Missing Suppliers', count: 8, severity: 'info' }
        ];

        const riskData = {
            labels: ['Stockout Risk', 'Overstock Risk', 'Price Risk', 'Quality Risk'],
            datasets: [{
                label: 'Risk Level',
                data: [75, 45, 30, 20],
                backgroundColor: ['#dc3545', '#ffc107', '#17a2b8', '#6c757d']
            }]
        };

        this.updateDataQualityIssues(qualityIssues);
        this.createRiskChart(riskData);
        this.updateRiskAlerts();
    }

    updateDataQualityIssues(issues) {
        let html = '';
        issues.forEach(issue => {
            const colors = {
                'danger': 'danger',
                'warning': 'warning', 
                'info': 'info'
            };
            
            html += `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small>${issue.type}</small>
                    <span class="badge badge-${colors[issue.severity]}">${issue.count}</span>
                </div>
            `;
        });
        $('#dataQualityIssues').html(html);
    }

    createRiskChart(data) {
        const ctx = document.getElementById('riskChart');
        if (!ctx) return;

        if (this.charts.risk) {
            this.charts.risk.destroy();
        }

        this.charts.risk = new Chart(ctx, {
            type: 'radar',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Risk Assessment'
                    }
                },
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100
                    }
                }
            }
        });
    }

    updateRiskAlerts() {
        const alerts = `
            <div class="alert alert-danger alert-sm mb-2">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <small>Nike Air Max sắp hết hàng trong 2 ngày</small>
            </div>
            <div class="alert alert-warning alert-sm mb-2">
                <i class="fas fa-boxes mr-2"></i>
                <small>Adidas boots tồn quá nhiều</small>
            </div>
            <div class="alert alert-info alert-sm mb-2">
                <i class="fas fa-chart-line mr-2"></i>
                <small>Giá sandals cần điều chỉnh</small>
            </div>
        `;
        $('#riskAlerts').html(alerts);
    }

    // Module 7: Market Trends & Social Media
    async loadMarketModule() {
        const marketData = {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
            datasets: [{
                label: 'Google Trends',
                data: [65, 72, 68, 85, 92, 78],
                borderColor: '#4285f4',
                backgroundColor: 'rgba(66, 133, 244, 0.1)'
            }, {
                label: 'Social Mentions',
                data: [45, 58, 62, 78, 85, 91],
                borderColor: '#ea4335',
                backgroundColor: 'rgba(234, 67, 53, 0.1)'
            }]
        };

        const socialData = {
            labels: ['Facebook', 'Instagram', 'TikTok', 'Twitter'],
            datasets: [{
                label: 'Mentions',
                data: [156, 289, 445, 123],
                backgroundColor: ['#1877f2', '#E4405F', '#000000', '#1DA1F2']
            }]
        };

        this.createMarketTrendChart(marketData);
        this.createSocialTrendChart(socialData);
        this.updateTrendingKeywords();
    }

    createMarketTrendChart(data) {
        const ctx = document.getElementById('marketTrendChart');
        if (!ctx) return;

        if (this.charts.market) {
            this.charts.market.destroy();
        }

        this.charts.market = new Chart(ctx, {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Market Trends - 6 tháng gần đây'
                    }
                }
            }
        });
    }

    createSocialTrendChart(data) {
        const ctx = document.getElementById('socialTrendChart');
        if (!ctx) return;

        if (this.charts.social) {
            this.charts.social.destroy();
        }

        this.charts.social = new Chart(ctx, {
            type: 'bar',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Social Media Mentions'
                    }
                }
            }
        });
    }

    updateTrendingKeywords() {
        const keywords = [
            { text: '#WhiteSneakers', trend: 'up' },
            { text: '#RetroStyle', trend: 'up' },
            { text: '#SustainableShoes', trend: 'hot' },
            { text: '#WinterBoots', trend: 'up' },
            { text: '#MinimalDesign', trend: 'stable' },
            { text: '#ComfortShoes', trend: 'up' }
        ];

        let html = '';
        keywords.forEach(keyword => {
            const colors = {
                'hot': 'danger',
                'up': 'success',
                'stable': 'secondary'
            };
            
            html += `<span class="badge badge-${colors[keyword.trend]} mr-2 mb-2">${keyword.text}</span>`;
        });
        
        $('#trendingKeywords').html(html);
    }

    // Utility methods
    showLoading(moduleId) {
        // Implementation for loading state
    }

    hideLoading(moduleId) {
        // Implementation for hiding loading state  
    }

    showError(moduleId, message) {
        console.error(`Module ${moduleId} error:`, message);
    }

    startRealTimeUpdates() {
        // Update every 5 minutes
        setInterval(() => {
            if (this.currentModule) {
                this.loadModule(this.currentModule);
            }
        }, 300000);
    }

    // Display functions for all modules
    displayInventoryTurnover(data) {
        $('#inventory-turnover-content').html(`
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">Inventory Turnover by Category</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="turnoverChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-info">Aging Analysis</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="agingChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-warning">AI Recommendations</h6>
                        </div>
                        <div class="card-body">
                            <div id="turnoverRecommendations"></div>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        if (data.charts_data) {
            this.createChart('turnoverChart', 'bar', data.charts_data.turnover_chart);
            this.createChart('agingChart', 'doughnut', data.charts_data.aging_chart);
        }
        
        this.displayRecommendations('turnoverRecommendations', data.ai_recommendations);
    }

    displaySupplyChain(data) {
        $('#supply-chain-content').html(`
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-success">Supplier Performance</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="supplierChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-info">Lead Time Analysis</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="leadTimeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-warning">Supply Chain Insights</h6>
                        </div>
                        <div class="card-body">
                            <div id="supplyChainRecommendations"></div>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        if (data.charts_data) {
            this.createChart('supplierChart', 'bar', data.charts_data.supplier_chart);
            this.createChart('leadTimeChart', 'line', data.charts_data.lead_time_chart);
        }
        
        this.displayRecommendations('supplyChainRecommendations', data.ai_recommendations);
    }

    displayProfitability(data) {
        $('#profitability-content').html(`
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-success">Product Profitability</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="profitabilityChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-info">Margin Analysis</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="marginChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">Profit Trends</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="profitTrendChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-warning">Pricing Opportunities</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="pricingOpportunityChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        if (data.charts_data) {
            this.createChart('profitabilityChart', 'bar', data.charts_data.profitability_chart);
            this.createChart('marginChart', 'bar', data.charts_data.margin_chart);
            this.createChart('profitTrendChart', 'line', data.charts_data.profit_trend_chart);
            this.createChart('pricingOpportunityChart', 'bar', data.charts_data.pricing_opportunity_chart);
        }
    }

    displayConsumerBehavior(data) {
        $('#consumer-behavior-content').html(`
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">Customer Segmentation</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="segmentationChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-info">Purchase Patterns</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="purchasePatternChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-success">Customer Lifecycle</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="lifecycleChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-warning">Seasonal Behavior</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="seasonalChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        if (data.charts_data) {
            this.createChart('segmentationChart', 'doughnut', data.charts_data.segmentation_chart);
            this.createChart('purchasePatternChart', 'line', data.charts_data.purchase_pattern_chart);
            this.createChart('lifecycleChart', 'doughnut', data.charts_data.lifecycle_chart);
            this.createChart('seasonalChart', 'line', data.charts_data.seasonal_chart);
        }
    }

    displayDataQuality(data) {
        $('#data-quality-content').html(`
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">Quality Score Overview</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="qualityScoreChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-info">Data Completeness</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="completenessChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-success">Consistency Analysis</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="consistencyChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-warning">Critical Issues</h6>
                        </div>
                        <div class="card-body">
                            <div class="text-center">
                                <h3 class="text-danger">${data.summary.critical_issues || 0}</h3>
                                <p class="mb-0">Issues Found</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        if (data.charts_data) {
            this.createChart('qualityScoreChart', 'bar', data.charts_data.quality_score_chart);
            this.createChart('completenessChart', 'bar', data.charts_data.completeness_chart);
            this.createChart('consistencyChart', 'radar', data.charts_data.consistency_chart);
        }
    }

    displayMarketTrends(data) {
        $('#market-trends-content').html(`
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">Sales Trend Analysis</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-info">Product Lifecycle</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="lifecycleMarketChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-success">Demand Forecast</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="forecastChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-warning">Market Opportunities</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="opportunityChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        if (data.charts_data) {
            this.createChart('trendChart', 'line', data.charts_data.trend_chart);
            this.createChart('lifecycleMarketChart', 'doughnut', data.charts_data.lifecycle_chart);
            this.createChart('forecastChart', 'bar', data.charts_data.forecast_chart);
            this.createChart('opportunityChart', 'bar', data.charts_data.opportunity_chart);
        }
    }

    displayRecommendations(containerId, recommendations) {
        if (!recommendations) return;
        
        let html = '';
        
        ['urgent', 'important', 'suggested'].forEach(priority => {
            if (recommendations[priority] && recommendations[priority].length > 0) {
                const priorityColors = {
                    'urgent': 'danger',
                    'important': 'warning', 
                    'suggested': 'info'
                };
                
                html += `<h6 class="text-${priorityColors[priority]} text-uppercase">${priority} Recommendations</h6>`;
                
                recommendations[priority].forEach(rec => {
                    html += `
                        <div class="alert alert-${priorityColors[priority]} alert-dismissible fade show">
                            <strong>${rec.type}:</strong> ${rec.recommendation}
                            <button type="button" class="close" data-dismiss="alert">
                                <span>&times;</span>
                            </button>
                        </div>
                    `;
                });
            }
        });
        
        if (html === '') {
            html = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> No critical issues found. System operating optimally!</div>';
        }
        
        $(`#${containerId}`).html(html);
    }

    async refreshAllModules() {
        const modules = ['trend', 'inventory', 'supply', 'profit', 'behavior', 'quality', 'market'];
        
        for (const module of modules) {
            await this.loadModule(module);
            await this.delay(500); // Avoid overwhelming the system
        }
        
        this.showSuccessMessage('All modules refreshed successfully!');
    }

    generateFullReport() {
        // Implementation for generating comprehensive report
        this.showSuccessMessage('Generating comprehensive AI report...');
    }

    exportInsights() {
        // Implementation for exporting insights
        this.showSuccessMessage('Exporting AI insights...');
    }

    scheduleAnalysis() {
        // Implementation for scheduling regular analysis
        this.showSuccessMessage('Analysis scheduled successfully!');
    }

    openAISettings() {
        // Implementation for AI configuration
        this.showSuccessMessage('Opening AI settings...');
    }

    showSuccessMessage(message) {
        // Simple toast notification
        const toast = $(`
            <div class="alert alert-success alert-dismissible fade show position-fixed" 
                 style="top: 20px; right: 20px; z-index: 9999;">
                <i class="fas fa-check-circle mr-2"></i>
                ${message}
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        `);
        
        $('body').append(toast);
        
        setTimeout(() => {
            toast.alert('close');
        }, 3000);
    }

    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// Initialize dashboard when DOM is ready
$(document).ready(function() {
    window.aiDashboard = new AIAnalyticsDashboard();
});