#!/usr/bin/env python3
"""
Smart Inventory Demand Forecasting Engine
==========================================
Pulls historical stock "out" movements (last 90 days), builds continuous daily time series,
evaluates Moving Average vs Exponential Smoothing (statsmodels), picks the best-fit model,
and writes 14-day forward predictions into MySQL `forecasts` table.

Designed to be executed periodically via cron.
"""

import os
import sys
import logging
from datetime import datetime, date, timedelta
import numpy as np
import pandas as pd
import pymysql

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
logger = logging.getLogger('InventoryForecaster')

# Database configuration from environment or defaults
DB_HOST = os.getenv('DB_HOST', '127.0.0.1')
DB_PORT = int(os.getenv('DB_PORT', 3306))
DB_NAME = os.getenv('DB_NAME', 'smart_inventory')
DB_USER = os.getenv('DB_USER', 'root')
DB_PASS = os.getenv('DB_PASS', '')


def get_db_connection():
    """Establish and return MySQL connection."""
    return pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False
    )


def compute_rmse(actuals: np.ndarray, predictions: np.ndarray) -> float:
    """Calculate Root Mean Squared Error."""
    mask = ~np.isnan(predictions) & ~np.isnan(actuals)
    if not np.any(mask):
        return float('inf')
    return float(np.sqrt(np.mean((actuals[mask] - predictions[mask]) ** 2)))


def forecast_product(series: pd.Series, horizon_days: int = 14):
    """
    Fit Moving Average and Exponential Smoothing on the daily series.
    Select the model with lower RMSE on historical data and return 14-day forecasts.
    """
    n = len(series)
    values = series.values.astype(float)

    # -------------------------------------------------------------
    # 1. Model A: Simple Moving Average (7-day window)
    # -------------------------------------------------------------
    ma_window = min(7, max(2, n // 3))
    ma_fitted = series.rolling(window=ma_window, min_periods=1).mean().values
    ma_rmse = compute_rmse(values, ma_fitted)

    # Forecast with MA is the mean of the most recent window
    recent_mean = float(np.mean(values[-ma_window:]))
    ma_forecast = [max(0.0, round(recent_mean, 2))] * horizon_days

    # -------------------------------------------------------------
    # 2. Model B: Exponential Smoothing (statsmodels)
    # -------------------------------------------------------------
    exp_rmse = float('inf')
    exp_forecast = None
    exp_model_name = 'exponential_smoothing'

    try:
        from statsmodels.tsa.holtwinters import SimpleExpSmoothing

        # Fit Simple Exponential Smoothing
        ses = SimpleExpSmoothing(series, initialization_method='estimated')
        ses_fit = ses.fit(optimized=True)
        exp_fitted = ses_fit.fittedvalues.values
        exp_rmse = compute_rmse(values, exp_fitted)

        # Forecast next 14 days
        raw_pred = ses_fit.forecast(horizon_days).values
        exp_forecast = [max(0.0, round(float(x), 2)) for x in raw_pred]
    except Exception as e:
        logger.debug("Exponential smoothing fallback: %s", e)

    # -------------------------------------------------------------
    # 3. Model Selection: Lower RMSE wins
    # -------------------------------------------------------------
    if exp_forecast is not None and exp_rmse < ma_rmse:
        chosen_model = exp_model_name
        predictions = exp_forecast
        chosen_rmse = exp_rmse
    else:
        chosen_model = 'moving_average'
        predictions = ma_forecast
        chosen_rmse = ma_rmse

    return chosen_model, predictions, chosen_rmse


def run_pipeline():
    """Main execution orchestrator."""
    logger.info("Starting demand forecasting pipeline...")
    logger.info("Connecting to MySQL (%s:%s/%s)...", DB_HOST, DB_PORT, DB_NAME)

    try:
        conn = get_db_connection()
    except Exception as err:
        logger.error("Failed to connect to MySQL database: %s", err)
        sys.exit(1)

    total_processed = 0
    total_forecasts_written = 0
    skipped_count = 0

    try:
        with conn.cursor() as cursor:
            # Fetch all active products
            cursor.execute("SELECT id, sku, name FROM products ORDER BY id ASC")
            products = cursor.fetchall()
            logger.info("Found %d products to evaluate.", len(products))

            today = date.today()
            horizon_days = 14

            for p in products:
                p_id = p['id']
                sku = p['sku']
                name = p['name']

                # Fetch daily out movements over past 90 days
                query = """
                    SELECT DATE(moved_at) AS date_val, SUM(quantity) AS daily_qty
                    FROM stock_movements
                    WHERE product_id = %s
                      AND type = 'out'
                      AND moved_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                    GROUP BY DATE(moved_at)
                    ORDER BY date_val ASC
                """
                cursor.execute(query, (p_id,))
                rows = cursor.fetchall()

                # Build a continuous 90-day time series
                end_date = today - timedelta(days=1)
                start_date = today - timedelta(days=90)
                date_index = pd.date_range(start=start_date, end=end_date, freq='D')

                data_dict = {r['date_val']: float(r['daily_qty']) for r in rows}
                series = pd.Series([data_dict.get(d.date(), 0.0) for d in date_index], index=date_index)

                # If no movements at all in 90 days, predict 0
                if series.sum() == 0 and len(rows) == 0:
                    chosen_model = 'zero_demand'
                    predictions = [0.0] * horizon_days
                    chosen_rmse = 0.0
                else:
                    chosen_model, predictions, chosen_rmse = forecast_product(series, horizon_days=horizon_days)

                # Store forecasts into database for next 14 days
                insert_sql = """
                    INSERT INTO forecasts (product_id, forecast_date, predicted_qty, model_used)
                    VALUES (%s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        predicted_qty = VALUES(predicted_qty),
                        model_used = VALUES(model_used),
                        created_at = CURRENT_TIMESTAMP
                """

                for i, pred_qty in enumerate(predictions):
                    f_date = today + timedelta(days=i + 1)
                    cursor.execute(insert_sql, (p_id, f_date, pred_qty, chosen_model))
                    total_forecasts_written += 1

                total_processed += 1
                logger.info(
                    "Product #%d [%s - %s]: Selected %s (RMSE=%.2f) -> 14-day avg: %.1f units/day",
                    p_id, sku, name, chosen_model, chosen_rmse, np.mean(predictions)
                )

            # Commit all changes
            conn.commit()
            logger.info("Successfully committed forecasts to database.")

    except Exception as e:
        conn.rollback()
        logger.error("Forecasting pipeline failed: %s", e, exc_info=True)
        sys.exit(1)
    finally:
        conn.close()

    logger.info(
        "Forecasting complete: %d products processed, %d forecasts generated, %d skipped.",
        total_processed, total_forecasts_written, skipped_count
    )


if __name__ == '__main__':
    run_pipeline()
