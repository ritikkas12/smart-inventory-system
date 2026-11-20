// Smart Inventory System JavaScript Helpers
document.addEventListener('DOMContentLoaded', () => {
    // 1. Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });

    // 2. Tab switcher
    const tabButtons = document.querySelectorAll('.tab-btn');
    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const tabGroup = btn.closest('.tabs-container') || document;
            const targetId = btn.dataset.tab;

            tabGroup.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            tabGroup.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            btn.classList.add('active');
            const targetContent = document.getElementById(targetId);
            if (targetContent) {
                targetContent.classList.add('active');
                // Trigger resize so charts in hidden tabs recalculate dimensions
                window.dispatchEvent(new Event('resize'));
            }
        });
    });

    // 3. Confirm prompts for destructive actions
    document.querySelectorAll('.btn-confirm').forEach(el => {
        el.addEventListener('click', (e) => {
            const message = el.dataset.confirm || 'Are you sure you want to proceed?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });
});

// 4. Interactive Forecast Chart Loader via Fetch
let forecastChartInstance = null;

function loadForecastChart(productId, canvasId = 'forecastChart') {
    if (!productId) return;
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    const infoEl = document.getElementById('forecastChartInfo');
    if (infoEl) infoEl.textContent = 'Loading forecast data...';

    fetch(`api/forecast_data.php?product_id=${encodeURIComponent(productId)}`)
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            if (data.error) {
                if (infoEl) infoEl.textContent = data.error;
                return;
            }

            const actuals = data.actual || [];
            const forecasts = data.forecast || [];

            // Build date map
            const dateLabels = [];
            const actualValues = [];
            const forecastValues = [];

            // Add actuals
            actuals.forEach(item => {
                dateLabels.push(item.date);
                actualValues.push(parseFloat(item.quantity));
                forecastValues.push(null);
            });

            // Bridge point between actual and forecast
            if (actuals.length > 0 && forecasts.length > 0) {
                const lastActual = actualValues[actualValues.length - 1];
                // Connect forecast line from last actual
                forecastValues[forecastValues.length - 1] = lastActual;
            }

            // Add forecasts
            forecasts.forEach(item => {
                dateLabels.push(item.date);
                actualValues.push(null);
                forecastValues.push(parseFloat(item.predicted_qty));
            });

            if (forecastChartInstance) {
                forecastChartInstance.destroy();
            }

            const ctx = canvas.getContext('2d');

            // Modern soft linear gradients
            const actualGradient = ctx.createLinearGradient(0, 0, 0, 350);
            actualGradient.addColorStop(0, 'rgba(79, 70, 229, 0.22)');
            actualGradient.addColorStop(1, 'rgba(79, 70, 229, 0.00)');

            const forecastGradient = ctx.createLinearGradient(0, 0, 0, 350);
            forecastGradient.addColorStop(0, 'rgba(245, 158, 11, 0.25)');
            forecastGradient.addColorStop(1, 'rgba(245, 158, 11, 0.00)');

            forecastChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: dateLabels,
                    datasets: [
                        {
                            label: 'Actual Usage (Daily OUT)',
                            data: actualValues,
                            borderColor: '#4f46e5',
                            backgroundColor: actualGradient,
                            fill: true,
                            tension: 0.35,
                            borderWidth: 2.5,
                            pointRadius: 3,
                            pointBackgroundColor: '#4f46e5',
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: '#ffffff',
                            pointHoverBorderColor: '#4f46e5',
                            pointHoverBorderWidth: 3
                        },
                        {
                            label: 'Predicted Demand (Next 14 Days)',
                            data: forecastValues,
                            borderColor: '#f59e0b',
                            backgroundColor: forecastGradient,
                            borderDash: [6, 4],
                            fill: true,
                            tension: 0.35,
                            borderWidth: 2.5,
                            pointRadius: 4,
                            pointBackgroundColor: '#f59e0b',
                            pointHoverRadius: 7,
                            pointHoverBackgroundColor: '#ffffff',
                            pointHoverBorderColor: '#f59e0b',
                            pointHoverBorderWidth: 3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end',
                            labels: {
                                font: {
                                    family: "'Plus Jakarta Sans', sans-serif",
                                    size: 12,
                                    weight: 600
                                },
                                usePointStyle: true,
                                pointStyle: 'circle',
                                padding: 18,
                                color: '#475569'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            titleFont: {
                                family: "'Plus Jakarta Sans', sans-serif",
                                size: 12,
                                weight: 700
                            },
                            bodyFont: {
                                family: "'Plus Jakarta Sans', sans-serif",
                                size: 12
                            },
                            padding: 12,
                            cornerRadius: 10,
                            caretSize: 6,
                            boxPadding: 6,
                            usePointStyle: true,
                            callbacks: {
                                label: function(context) {
                                    const val = context.parsed.y;
                                    return val !== null ? ` ${context.dataset.label}: ${val.toFixed(1)} units` : '';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxTicksLimit: 12,
                                font: {
                                    family: "'Plus Jakarta Sans', sans-serif",
                                    size: 11
                                },
                                color: '#64748b'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Quantity (Units)',
                                font: {
                                    family: "'Plus Jakarta Sans', sans-serif",
                                    size: 11,
                                    weight: 600
                                },
                                color: '#64748b'
                            },
                            grid: {
                                color: '#f1f5f9'
                            },
                            ticks: {
                                font: {
                                    family: "'Plus Jakarta Sans', sans-serif",
                                    size: 11
                                },
                                color: '#64748b'
                            }
                        }
                    }
                }
            });

            if (infoEl) {
                const modelUsed = forecasts.length > 0 && forecasts[0].model_used ? forecasts[0].model_used : 'Exponential Smoothing';
                infoEl.textContent = `Showing 30-day actual history + 14-day forecast for ${data.product.name} (SKU: ${data.product.sku}) using ${modelUsed.replace('_', ' ')}.`;
            }
        })
        .catch(err => {
            console.error('Error fetching forecast:', err);
            if (infoEl) infoEl.textContent = 'Failed to load forecast data.';
        });
}
