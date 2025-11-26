/**
 * app_error.js
 * Shared error handling utilities for frontend (DataTables/jQuery/fetch).
 */
(function (global) {
    'use strict';

    const win = global;
    const body = global.document?.body;
    const attrValue = body?.getAttribute('data-logging-enabled');
    const mergedConfig = {
        ...(win.APP_CONFIG || {}),
        loggingEnabled:
            attrValue === 'false'
                ? false
                : (win.APP_CONFIG && win.APP_CONFIG.loggingEnabled) !== undefined
                    ? win.APP_CONFIG.loggingEnabled
                    : true,
        env: (win.APP_CONFIG && win.APP_CONFIG.env) || 'production',
    };
    win.APP_CONFIG = mergedConfig;

    /**
     * Extract correlation ID from payload or response headers.
     */
    const extractCorrelationId = (payload, responseLike) => {
        if (payload) {
            if (payload.correlationId) return payload.correlationId;
            if (payload.correlation_id) return payload.correlation_id;
            if (payload.error?.correlationId) return payload.error.correlationId;
            if (payload.error?.correlation_id) return payload.error.correlation_id;
        }
        if (responseLike?.headers?.get) {
            const headerVal = responseLike.headers.get('X-Correlation-ID');
            if (headerVal) return headerVal;
        }
        if (responseLike?.getResponseHeader) {
            const headerVal = responseLike.getResponseHeader('X-Correlation-ID');
            if (headerVal) return headerVal;
        }
        return null;
    };

    /**
     * Build a user-facing message that includes a correlation ID reference when present.
     */
    const buildUserMessage = (message, correlationId) =>
        correlationId ? `${message} (Ref: ${correlationId})` : message;

    /**
     * Parse JSON safely.
     */
    const safeParseJson = (text) => {
        if (!text) return null;
        try {
            return JSON.parse(text);
        } catch (error) {
            return null;
        }
    };

    /**
     * Dev-only console logger (suppressed when loggingEnabled=false or env=production).
     */
    const logDev = (entry) => {
        if (win.APP_CONFIG.loggingEnabled === false) {
            return;
        }
        if ((win.APP_CONFIG.env || 'production') === 'production') {
            return;
        }
        const payload = {
            ...entry,
        };
        // Avoid dumping large payloads or PII—expect callers to sanitize context.
        // eslint-disable-next-line no-console
        console.log('[app]', payload);
    };

    /**
     * Normalize jQuery AJAX failure into an Error with correlation ID.
     */
    const handleAjaxFailure = (jqXHR, endpoint, fallbackMessage = 'Request failed.') => {
        const payload = jqXHR?.responseJSON ?? safeParseJson(jqXHR?.responseText);
        const correlationId = extractCorrelationId(payload, jqXHR);
        const message =
            payload?.error?.message ??
            payload?.message ??
            fallbackMessage;
        const errorCode =
            payload?.error?.code ??
            payload?.errorCode ??
            jqXHR?.statusText ??
            'request_failed';

        logDev({
            level: 'error',
            endpoint,
            status: jqXHR?.status,
            errorCode,
            correlationId,
            message,
        });

        const error = new Error(buildUserMessage(message, correlationId));
        error.correlationId = correlationId;
        error.errorCode = errorCode;
        return error;
    };

    win.AppError = {
        extractCorrelationId,
        buildUserMessage,
        logDev,
        handleAjaxFailure,
    };
})(window);
