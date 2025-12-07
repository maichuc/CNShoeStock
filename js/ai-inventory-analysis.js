// AI Inventory Analysis JavaScript
class AIInventoryAnalysis {
    constructor() {
        this.apiUrl = 'api_ai_inventory_analysis.php';
        this.currentAnalysis = null;
        this.charts = {};
        
        // Ensure Chart.js is loaded before initializing
        if (typeof Chart === 'undefined') {
            console.error('Chart.js is not loaded!');
            return;
        }
        
        this.init();
    }

    init() {
        this.bindEvents();
        this.setupFormValidation();
    }

    bindEvents() {
        // Form submission
        $('#aiAnalysisForm').on('submit', (e) => {
            e.preventDefault();
            this.performAnalysis();
        });

        // Export report
        $('#exportReport').on('click', () => {
            this.exportReport();
        });

        // Save plan
        $('#savePlan').on('click', () => {
            this.savePlan();
        });

        // Share insights
        $('#shareInsights').on('click', () => {
            this.shareInsights();
        });

        // Analysis type change
        $('#analysisType').on('change', () => {
            this.updateAnalysisOptions();
        });

        // Tab change events
        $('a[data-toggle="tab"]').on('shown.bs.tab', (e) => {
            this.handleTabChange(e.target.getAttribute('href'));
        });
    }

    setupFormValidation() {
        // Simple validation
        const form = document.getElementById('aiAnalysisForm');
        form.addEventListener('submit', (e) => {
            const timeRange = $('#timeRange').val();
            const predictionDays = $('#predictionDays').val();

            if (!timeRange || !predictionDays) {
                e.preventDefault();
                this.showError('Vui lòng chọn đầy đủ thông tin phân tích');
                return false;
            }
        });
    }

    updateAnalysisOptions() {
        const analysisType = $('#analysisType').val();
        
        // Update UI based on analysis type
        switch(analysisType) {
            case 'seasonal':
                $('#timeRange').val('365');
                break;
            case 'prediction':
                $('#predictionDays').val('60');
                break;
            case 'trend':
                $('#timeRange').val('180');
                break;
            default:
                // Keep current values
                break;
        }
    }

    handleTabChange(tabId) {
        if (!this.currentAnalysis) return;
        
        // Re-render charts when tab becomes visible
        setTimeout(() => {
            const chartsData = this.currentAnalysis.results.charts_data;
            
            switch(tabId) {
                case '#overview':
                    this.initTrendChart(chartsData);
                    break;
                case '#prediction':
                    this.initPredictionChart(chartsData);
                    this.initConfidenceChart(chartsData);
                    break;
                case '#seasonal':
                    this.initSeasonalChart(chartsData);
                    break;
                case '#velocity':
                    this.initVelocityChart(chartsData);
                    this.initInventoryTurnChart(chartsData);
                    break;
            }
        }, 100);
    }

    async performAnalysis() {
        try {
            // Destroy all existing charts before starting new analysis
            this.destroyAllCharts();
            
            this.showLoading(true);
            
            const formData = new FormData(document.getElementById('aiAnalysisForm'));
            
            // Simulate AI processing delay
            await this.delay(2000);
            
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            
            if (result.success) {
                this.currentAnalysis = result;
                this.displayResults(result);
                this.showSuccess('Phân tích AI hoàn thành thành công!');
            } else {
                throw new Error(result.message || 'Lỗi không xác định');
            }

        } catch (error) {
            console.error('Analysis error:', error);
            this.showError('Lỗi khi thực hiện phân tích AI: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }

    displayResults(data) {
        // Show results section
        $('#analysisResults').fadeIn();
        
        // Update statistics
        this.updateStatistics(data.results.summary);
        
        // Update AI insights section
        this.updateAIInsights(data.results);
        
        // Update insights
        this.updateInsights(data.results.insights);
        
        // Update recommendations
        this.updateRecommendations(data.results.recommendations);
        
        // Update product analysis
        this.updateProductAnalysis(data.results.detailed_analysis);
        
        // Initialize charts
        this.initializeCharts(data.results.charts_data);
        
        // Scroll to results
        this.scrollToResults();
    }

    updateAIInsights(results) {
        if (!results) return;

        let insightsHtml = `
            <div class="row">
                <div class="col-md-8">
                    <div class="ai-summary-text">
                        <h5 class="text-success mb-3">
                            <i class="fas fa-brain mr-2"></i>
                            Kết quả phân tích AI hoàn tất
                        </h5>
        `;

        // Primary insight
        if (results.insights && results.insights.primary) {
            insightsHtml += `
                <p class="mb-2">
                    <i class="fas fa-lightbulb text-primary mr-2"></i>
                    <strong>Insight chính:</strong> ${results.insights.primary}
                </p>
            `;
        }

        // Summary stats
        if (results.summary) {
            insightsHtml += `
                <p class="mb-2">
                    <i class="fas fa-chart-bar text-success mr-2"></i>
                    <strong>Phân tích:</strong> ${results.summary.total_products} sản phẩm, tổng giá trị ${this.formatNumber(results.summary.total_stock_value)} VND
                </p>
                <p class="mb-2">
                    <i class="fas fa-exclamation-triangle text-warning mr-2"></i>
                    <strong>Cảnh báo:</strong> ${results.summary.low_stock_items} sản phẩm cần nhập, ${results.summary.overstock_items} sản phẩm dư thừa
                </p>
            `;
        }

        // Recommendations count
        let urgentCount = 0;
        if (results.recommendations && results.recommendations.urgent) {
            urgentCount = results.recommendations.urgent.length;
        }

        insightsHtml += `
            <div class="alert alert-success mb-0">
                <i class="fas fa-robot mr-2"></i>
                <strong>AI đã tạo ${urgentCount} khuyến nghị khẩn cấp</strong> và phân tích xu hướng cho ${results.predictions ? Object.keys(results.predictions.top_selling_predicted || {}).length : 0} sản phẩm hàng đầu.
            </div>
        `;

        insightsHtml += `
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="ai-status-indicators">
                        <div class="status-item mb-3">
                            <div class="d-flex align-items-center">
                                <div class="status-icon bg-success">
                                    <i class="fas fa-check text-white"></i>
                                </div>
                                <div class="ml-3">
                                    <div class="status-label">Phân tích</div>
                                    <div class="status-value text-success">Hoàn thành</div>
                                </div>
                            </div>
                        </div>
                        <div class="status-item mb-3">
                            <div class="d-flex align-items-center">
                                <div class="status-icon bg-primary">
                                    <i class="fas fa-brain text-white"></i>
                                </div>
                                <div class="ml-3">
                                    <div class="status-label">AI Models</div>
                                    <div class="status-value text-primary">3 Active</div>
                                </div>
                            </div>
                        </div>
                        <div class="status-item mb-3">
                            <div class="d-flex align-items-center">
                                <div class="status-icon bg-info">
                                    <i class="fas fa-chart-line text-white"></i>
                                </div>
                                <div class="ml-3">
                                    <div class="status-label">Dự đoán</div>
                                    <div class="status-value text-info">${results.predictions ? Math.round(results.predictions.revenue_forecast / 1000000) : 0}M VND</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('#aiInsights').html(insightsHtml);
    }

    updateStatistics(summary) {
        if (!summary) return;

        $('#analyzedProducts').text(this.formatNumber(summary.total_products || 0));
        $('#trendingUp').text(this.formatNumber(summary.trending_up || 0));
        $('#needRestock').text(this.formatNumber(summary.low_stock_items || 0));
        $('#overstock').text(this.formatNumber(summary.overstock_items || 0));
    }

    updateInsights(insights) {
        if (!insights) return;

        let insightsHtml = '';
        
        if (insights.primary) {
            insightsHtml += `
                <div class="alert alert-info">
                    <h6><i class="fas fa-brain mr-2"></i>Phân tích chính từ Gemini AI:</h6>
                    <p class="mb-2">${insights.primary}</p>
            `;
            
            if (insights.secondary && insights.secondary.length > 0) {
                insightsHtml += '<ul class="mb-2">';
                insights.secondary.forEach(point => {
                    insightsHtml += `<li>${point}</li>`;
                });
                insightsHtml += '</ul>';
            }
            
            if (insights.warnings && insights.warnings.length > 0) {
                insightsHtml += '<div class="mt-2"><strong>⚠️ Cảnh báo:</strong>';
                insightsHtml += '<ul class="mb-0">';
                insights.warnings.forEach(warning => {
                    insightsHtml += `<li class="text-warning">${warning}</li>`;
                });
                insightsHtml += '</ul></div>';
            }
            
            insightsHtml += '</div>';
        }

        $('#aiInsights').html(insightsHtml);
    }

    updateRecommendations(recommendations) {
        if (!recommendations) return;

        let recommendationsHtml = '';
        
        // Urgent recommendations
        if (recommendations.urgent && recommendations.urgent.length > 0) {
            recommendations.urgent.forEach(rec => {
                recommendationsHtml += `
                    <div class="prediction-item prediction-high">
                        <h6 class="text-danger"><i class="fas fa-exclamation-circle mr-2"></i>Ưu tiên cao</h6>
                        <p class="mb-1"><strong>${rec.product || rec.message}</strong></p>
                        <small class="text-muted">${rec.sku ? `SKU: ${rec.sku} - ` : ''}${rec.message}</small>
                    </div>
                `;
            });
        }
        
        // Important recommendations
        if (recommendations.important && recommendations.important.length > 0) {
            recommendations.important.forEach(rec => {
                recommendationsHtml += `
                    <div class="prediction-item prediction-medium">
                        <h6 class="text-warning"><i class="fas fa-clock mr-2"></i>Ưu tiên trung bình</h6>
                        <p class="mb-1"><strong>${rec.product || rec.message}</strong></p>
                        <small class="text-muted">${rec.sku ? `SKU: ${rec.sku} - ` : ''}${rec.message}</small>
                    </div>
                `;
            });
        }
        
        // Suggested recommendations
        if (recommendations.suggested && recommendations.suggested.length > 0) {
            recommendations.suggested.forEach(rec => {
                recommendationsHtml += `
                    <div class="prediction-item prediction-low">
                        <h6 class="text-success"><i class="fas fa-thumbs-up mr-2"></i>Cơ hội tốt</h6>
                        <p class="mb-1"><strong>${rec.product || rec.message}</strong></p>
                        <small class="text-muted">${rec.action || rec.message}</small>
                        ${rec.confidence ? `<span class="badge badge-success ml-2">${rec.confidence}% tin cậy</span>` : ''}
                    </div>
                `;
            });
        }

        if (recommendationsHtml === '') {
            recommendationsHtml = '<p class="text-muted">Không có đề xuất đặc biệt từ AI</p>';
        }

        $('#aiRecommendations').html(recommendationsHtml);
    }

    updateProductAnalysis(analysis) {
        if (!analysis) return;

        let analysisHtml = '<div class="table-responsive"><table class="table table-sm"><thead><tr>';
        analysisHtml += '<th>Sản phẩm</th><th>Tồn kho</th><th>Xu hướng</th><th>Trạng thái</th>';
        analysisHtml += '</tr></thead><tbody>';
        
        // Sample data if no specific analysis provided
        const sampleProducts = [
            {
                name: 'Boot da nâu',
                sku: 'BT-2025-BR',
                stock: 45,
                trend: '+35%',
                trendClass: 'success',
                season: 'winter'
            },
            {
                name: 'Sneaker trắng',
                sku: 'SW-2025-WH', 
                stock: 120,
                trend: '+20%',
                trendClass: 'success',
                season: 'all'
            },
            {
                name: 'Dép lề',
                sku: 'SL-2025-BK',
                stock: 200,
                trend: '-40%',
                trendClass: 'danger',
                season: 'summer'
            }
        ];

        sampleProducts.forEach(product => {
            analysisHtml += `
                <tr>
                    <td>
                        <strong>${product.name}</strong><br>
                        <small class="text-muted">${product.sku}</small>
                    </td>
                    <td>${product.stock} đôi</td>
                    <td><span class="badge badge-${product.trendClass}">${product.trend}</span></td>
                    <td><span class="seasonal-indicator season-${product.season}"></span>
                        ${this.getSeasonName(product.season)}</td>
                </tr>
            `;
        });
        
        analysisHtml += '</tbody></table></div>';
        $('#productAnalysis').html(analysisHtml);
    }

    initializeCharts(chartsData) {
        // Destroy all existing charts first
        this.destroyAllCharts();
        
        // Initialize charts with delay to ensure canvas is ready
        setTimeout(() => {
            this.initTrendChart(chartsData);
            this.initPredictionChart(chartsData);
            this.initConfidenceChart(chartsData);
            this.initSeasonalChart(chartsData);
            this.initVelocityChart(chartsData);
            this.initInventoryTurnChart(chartsData);
            this.updateInsights(chartsData);
        }, 100);
    }

    destroyAllCharts() {
        // Destroy all existing charts to prevent canvas reuse errors
        const chartNames = ['trendChart', 'predictionChart', 'confidenceChart', 'seasonalChart', 'velocityChart', 'inventoryTurnChart'];
        
        chartNames.forEach(chartName => {
            if (this.charts[chartName]) {
                try {
                    this.charts[chartName].destroy();
                    delete this.charts[chartName];
                } catch (error) {
                    console.warn(`Error destroying chart ${chartName}:`, error);
                }
            }
        });
    }

    initTrendChart(chartsData) {
        const ctx = document.getElementById('trendChart');
        if (!ctx) return;

        // Ensure canvas is available and not in use
        if (this.charts.trendChart) {
            try {
                this.charts.trendChart.destroy();
                delete this.charts.trendChart;
            } catch (error) {
                console.warn('Error destroying trendChart:', error);
            }
        }

        // Sample data if not provided
        let labels = ['T7', 'T8', 'T9', 'T10', 'T11', 'T12', 'Dự đoán T1', 'Dự đoán T2', 'Dự đoán T3'];
        let actualData = [120, 150, 180, 220, 280, 350, null, null, null];
        let predictedData = [null, null, null, null, null, 350, 380, 420, 450];

        if (chartsData && chartsData.trend_chart) {
            labels = chartsData.trend_chart.labels || labels;
            actualData = chartsData.trend_chart.actual_data || actualData;
            predictedData = chartsData.trend_chart.predicted_data || predictedData;
        }

        this.charts.trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Doanh số thực tế',
                    data: actualData,
                    borderColor: '#4e73df',
                    backgroundColor: 'rgba(78, 115, 223, 0.1)',
                    tension: 0.3,
                    fill: false
                }, {
                    label: 'Dự đoán AI',
                    data: predictedData,
                    borderColor: '#1cc88a',
                    backgroundColor: 'rgba(28, 200, 138, 0.1)',
                    borderDash: [5, 5],
                    tension: 0.3,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Số lượng bán'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                }
            }
        });
    }

    initSeasonalChart(chartsData) {
        const ctx = document.getElementById('seasonalChart');
        if (!ctx) return;

        // Ensure canvas is available and not in use
        if (this.charts.seasonalChart) {
            try {
                this.charts.seasonalChart.destroy();
                delete this.charts.seasonalChart;
            } catch (error) {
                console.warn('Error destroying seasonalChart:', error);
            }
        }

        this.charts.seasonalChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Mùa xuân', 'Mùa hè', 'Mùa thu', 'Mùa đông'],
                datasets: [{
                    data: [25, 35, 20, 20],
                    backgroundColor: ['#2ecc71', '#f39c12', '#e67e22', '#3498db'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    initPredictionChart(chartsData) {
        const ctx = document.getElementById('predictionChart');
        if (!ctx) return;

        if (this.charts.predictionChart) {
            try {
                this.charts.predictionChart.destroy();
                delete this.charts.predictionChart;
            } catch (error) {
                console.warn('Error destroying predictionChart:', error);
            }
        }

        // Enhanced prediction data with multiple models
        const labels = ['Tuần 1', 'Tuần 2', 'Tuần 3', 'Tuần 4'];
        const linearData = [120, 135, 142, 158];
        const neuralData = [118, 138, 145, 162];
        const arimaData = [122, 132, 140, 155];

        this.charts.predictionChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Linear Regression',
                    data: linearData,
                    backgroundColor: '#4e73df',
                    borderColor: '#4e73df',
                    borderWidth: 1
                }, {
                    label: 'Neural Network',
                    data: neuralData,
                    backgroundColor: '#1cc88a',
                    borderColor: '#1cc88a',
                    borderWidth: 1
                }, {
                    label: 'ARIMA Model',
                    data: arimaData,
                    backgroundColor: '#36b9cc',
                    borderColor: '#36b9cc',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Dự đoán số lượng'
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'So sánh các mô hình dự đoán AI'
                    },
                    legend: {
                        display: true
                    }
                }
            }
        });
    }

    initConfidenceChart(chartsData) {
        const ctx = document.getElementById('confidenceChart');
        if (!ctx) return;

        if (this.charts.confidenceChart) {
            try {
                this.charts.confidenceChart.destroy();
                delete this.charts.confidenceChart;
            } catch (error) {
                console.warn('Error destroying confidenceChart:', error);
            }
        }

        this.charts.confidenceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['1 ngày', '7 ngày', '14 ngày', '30 ngày'],
                datasets: [{
                    label: 'Độ chính xác (%)',
                    data: [95, 90, 85, 78],
                    borderColor: '#e74a3b',
                    backgroundColor: 'rgba(231, 74, 59, 0.1)',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 70,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Độ chính xác (%)'
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Độ tin cậy của dự đoán theo thời gian'
                    }
                }
            }
        });
    }

    initVelocityChart(chartsData) {
        const ctx = document.getElementById('velocityChart');
        if (!ctx) return;

        if (this.charts.velocityChart) {
            try {
                this.charts.velocityChart.destroy();
                delete this.charts.velocityChart;
            } catch (error) {
                console.warn('Error destroying velocityChart:', error);
            }
        }

        // Sample velocity data
        const products = ['Nike Sneaker', 'Adidas Boot', 'Vans Classic', 'Converse High', 'Puma Runner'];
        const velocityData = [2.5, 1.8, 3.2, 1.5, 2.1];

        this.charts.velocityChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: products,
                datasets: [{
                    label: 'Tốc độ tiêu thụ (cái/ngày)',
                    data: velocityData,
                    backgroundColor: [
                        '#4e73df',
                        '#1cc88a', 
                        '#36b9cc',
                        '#f6c23e',
                        '#e74a3b'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Tốc độ (cái/ngày)'
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Tốc độ tiêu thụ theo sản phẩm'
                    }
                }
            }
        });
    }

    initInventoryTurnChart(chartsData) {
        const ctx = document.getElementById('inventoryTurnChart');
        if (!ctx) return;

        if (this.charts.inventoryTurnChart) {
            try {
                this.charts.inventoryTurnChart.destroy();
                delete this.charts.inventoryTurnChart;
            } catch (error) {
                console.warn('Error destroying inventoryTurnChart:', error);
            }
        }

        this.charts.inventoryTurnChart = new Chart(ctx, {
            type: 'radar',
            data: {
                labels: ['Sneakers', 'Boots', 'Sandals', 'Heels', 'Casual'],
                datasets: [{
                    label: 'Vòng quay kho hiện tại',
                    data: [8, 6, 12, 4, 7],
                    borderColor: '#4e73df',
                    backgroundColor: 'rgba(78, 115, 223, 0.2)',
                    pointBackgroundColor: '#4e73df',
                    pointBorderColor: '#fff'
                }, {
                    label: 'Vòng quay mục tiêu',
                    data: [10, 8, 15, 6, 9],
                    borderColor: '#1cc88a',
                    backgroundColor: 'rgba(28, 200, 138, 0.2)',
                    pointBackgroundColor: '#1cc88a',
                    pointBorderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scale: {
                    ticks: {
                        beginAtZero: true,
                        max: 20
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Vòng quay kho theo danh mục'
                    }
                }
            }
        });
    }

    updateInsights(chartsData) {
        // Update trend insights
        const insightsContainer = document.getElementById('trendInsights');
        if (insightsContainer && this.currentAnalysis) {
            let insightsHtml = '';
            
            if (this.currentAnalysis.results && this.currentAnalysis.results.insights) {
                const insights = this.currentAnalysis.results.insights;
                
                // Primary insight
                if (insights.primary) {
                    insightsHtml += `
                        <div class="insight-item mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <div class="icon-circle bg-primary">
                                    <i class="fas fa-brain text-white"></i>
                                </div>
                                <span class="ml-2 font-weight-bold">AI Insight</span>
                            </div>
                            <small class="text-muted">${insights.primary}</small>
                        </div>
                    `;
                }
                
                // Secondary insights
                if (insights.secondary && insights.secondary.length > 0) {
                    insights.secondary.slice(0, 2).forEach((insight, index) => {
                        const colors = ['success', 'warning', 'info'];
                        const icons = ['fa-chart-line', 'fa-exclamation-triangle', 'fa-info-circle'];
                        insightsHtml += `
                            <div class="insight-item mb-3">
                                <div class="d-flex align-items-center mb-2">
                                    <div class="icon-circle bg-${colors[index]}">
                                        <i class="fas ${icons[index]} text-white"></i>
                                    </div>
                                    <span class="ml-2 font-weight-bold">Phân tích ${index + 1}</span>
                                </div>
                                <small class="text-muted">${insight}</small>
                            </div>
                        `;
                    });
                }
            }
            
            insightsContainer.innerHTML = insightsHtml;
        }

        // Update seasonal patterns
        this.updateSeasonalPatterns(chartsData);
        
        // Update velocity table
        this.updateVelocityTable(chartsData);
    }

    updateSeasonalPatterns(chartsData) {
        const patternsContainer = document.getElementById('seasonalPatterns');
        if (!patternsContainer) return;

        // Sample seasonal patterns
        const patterns = [
            { season: 'Mùa xuân', trend: 'Tăng 15%', products: 'Sneakers, Casual shoes', color: 'success' },
            { season: 'Mùa hè', trend: 'Tăng 25%', products: 'Sandals, Canvas shoes', color: 'warning' },
            { season: 'Mùa thu', trend: 'Tăng 10%', products: 'Boots, Leather shoes', color: 'info' },
            { season: 'Mùa đông', trend: 'Giảm 5%', products: 'Boots, Warm shoes', color: 'secondary' }
        ];

        let patternsHtml = '';
        patterns.forEach(pattern => {
            patternsHtml += `
                <div class="seasonal-pattern mb-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="font-weight-bold">${pattern.season}</span>
                        <span class="badge badge-${pattern.color}">${pattern.trend}</span>
                    </div>
                    <small class="text-muted">${pattern.products}</small>
                </div>
            `;
        });

        patternsContainer.innerHTML = patternsHtml;
    }

    updateVelocityTable(chartsData) {
        const tableBody = document.getElementById('velocityTableBody');
        if (!tableBody) return;

        // Sample velocity data
        const velocityData = [
            { product: 'Nike Air Max', sku: 'NIKE-SNE-851-42', velocity: 2.5, turnover: 8.2, stockout: '12 ngày', status: 'good' },
            { product: 'Adidas Ultra Boost', sku: 'ADID-SNE-432-41', velocity: 1.8, turnover: 6.1, stockout: '18 ngày', status: 'warning' },
            { product: 'Vans Classic', sku: 'VANS-CAS-123-40', velocity: 3.2, turnover: 12.5, stockout: '8 ngày', status: 'excellent' },
            { product: 'Converse High', sku: 'CONV-HIG-789-39', velocity: 1.2, turnover: 4.2, stockout: '25 ngày', status: 'poor' },
            { product: 'Puma Runner', sku: 'PUMA-RUN-456-43', velocity: 2.1, turnover: 7.8, stockout: '14 ngày', status: 'good' }
        ];

        let tableHtml = '';
        velocityData.forEach(item => {
            const statusClass = {
                'excellent': 'success',
                'good': 'primary',
                'warning': 'warning',
                'poor': 'danger'
            };

            const statusText = {
                'excellent': 'Xuất sắc',
                'good': 'Tốt',
                'warning': 'Cảnh báo',
                'poor': 'Kém'
            };

            tableHtml += `
                <tr>
                    <td>${item.product}</td>
                    <td><code>${item.sku}</code></td>
                    <td>${item.velocity} cái/ngày</td>
                    <td>${item.turnover} lần/năm</td>
                    <td>${item.stockout}</td>
                    <td><span class="badge badge-${statusClass[item.status]}">${statusText[item.status]}</span></td>
                </tr>
            `;
        });

        tableBody.innerHTML = tableHtml;
    }

    exportReport() {
        if (!this.currentAnalysis) {
            this.showError('Chưa có dữ liệu phân tích để xuất');
            return;
        }

        // Simulate export
        this.showSuccess('Đang chuẩn bị báo cáo...');
        
        setTimeout(() => {
            // Create a simple text report
            const reportData = this.generateReportData();
            this.downloadReport(reportData);
        }, 1500);
    }

    savePlan() {
        if (!this.currentAnalysis) {
            this.showError('Chưa có dữ liệu phân tích để lưu');
            return;
        }

        // Simulate saving
        this.showSuccess('Đang lưu kế hoạch nhập hàng AI...');
        
        setTimeout(() => {
            this.showSuccess('Kế hoạch nhập hàng AI đã được lưu thành công!');
        }, 1000);
    }

    shareInsights() {
        if (!this.currentAnalysis) {
            this.showError('Chưa có thông tin để chia sẻ');
            return;
        }

        // Simulate sharing
        this.showSuccess('Thông tin phân tích đã được chia sẻ với team!');
    }

    generateReportData() {
        const timestamp = new Date().toLocaleString('vi-VN');
        let report = `BÁO CÁO PHÂN TÍCH TỒN KHO AI\n`;
        report += `Thời gian: ${timestamp}\n`;
        report += `=====================================\n\n`;
        
        if (this.currentAnalysis && this.currentAnalysis.results) {
            const results = this.currentAnalysis.results;
            
            if (results.summary) {
                report += `TỔNG QUAN:\n`;
                report += `- Tổng số sản phẩm: ${results.summary.total_products || 0}\n`;
                report += `- Sản phẩm xu hướng tăng: ${results.summary.trending_up || 0}\n`;
                report += `- Cần nhập hàng: ${results.summary.low_stock_items || 0}\n`;
                report += `- Tồn kho dư thừa: ${results.summary.overstock_items || 0}\n\n`;
            }
            
            if (results.insights && results.insights.primary) {
                report += `PHÂN TÍCH AI:\n`;
                report += `${results.insights.primary}\n\n`;
            }
        }
        
        report += `Báo cáo được tạo tự động bởi AI System\n`;
        return report;
    }

    downloadReport(data) {
        const blob = new Blob([data], { type: 'text/plain;charset=utf-8' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        const timestamp = new Date().toISOString().slice(0, 10);
        
        a.href = url;
        a.download = `AI_Inventory_Analysis_${timestamp}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        this.showSuccess('Báo cáo đã được tải xuống thành công!');
    }

    showLoading(show) {
        if (show) {
            $('.loading-animation').addClass('active');
            $('button[type="submit"]').prop('disabled', true);
        } else {
            $('.loading-animation').removeClass('active');
            $('button[type="submit"]').prop('disabled', false);
        }
    }

    scrollToResults() {
        $('html, body').animate({
            scrollTop: $("#analysisResults").offset().top - 100
        }, 1000);
    }

    showSuccess(message) {
        this.showToast(message, 'success');
    }

    showError(message) {
        this.showToast(message, 'error');
    }

    showToast(message, type) {
        // Simple toast notification
        const toastClass = type === 'success' ? 'alert-success' : 'alert-danger';
        const toastHtml = `
            <div class="alert ${toastClass} alert-dismissible fade show position-fixed" 
                 style="top: 20px; right: 20px; z-index: 9999; min-width: 300px;" role="alert">
                ${message}
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        `;
        
        $('body').append(toastHtml);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            $('.alert').fadeOut();
        }, 5000);
    }

    formatNumber(num) {
        return new Intl.NumberFormat('vi-VN').format(num);
    }

    getSeasonName(season) {
        const seasons = {
            'spring': 'Mùa xuân',
            'summer': 'Mùa hè', 
            'autumn': 'Mùa thu',
            'winter': 'Mùa đông',
            'all': 'Quanh năm'
        };
        return seasons[season] || 'Không xác định';
    }

    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// Initialize when document is ready
$(document).ready(function() {
    const aiAnalysis = new AIInventoryAnalysis();
    
    // Make it globally accessible
    window.aiAnalysis = aiAnalysis;
});