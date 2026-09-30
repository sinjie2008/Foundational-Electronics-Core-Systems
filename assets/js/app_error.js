/** Shared frontend error and correlation-ID handling. */
class AppErrorHandler {
    constructor(globalObject) {
        this.global = globalObject;
        const body = globalObject.document?.body;
        const loggingAttribute = body?.getAttribute('data-logging-enabled');
        const existingConfig = globalObject.APP_CONFIG || {};

        globalObject.APP_CONFIG = {
            ...existingConfig,
            loggingEnabled:
                loggingAttribute === 'false'
                    ? false
                    : existingConfig.loggingEnabled !== undefined
                        ? existingConfig.loggingEnabled
                        : true,
            env: existingConfig.env || 'production',
        };
    }

    /** Extract a correlation ID from a payload or response headers. */
    extractCorrelationId(payload, responseLike) {
        if (payload) {
            if (payload.correlationId) return payload.correlationId;
            if (payload.correlation_id) return payload.correlation_id;
            if (payload.error?.correlationId) return payload.error.correlationId;
            if (payload.error?.correlation_id) return payload.error.correlation_id;
        }
        if (responseLike?.headers?.get) {
            const headerValue = responseLike.headers.get('X-Correlation-ID');
            if (headerValue) return headerValue;
        }
        if (responseLike?.getResponseHeader) {
            const headerValue = responseLike.getResponseHeader('X-Correlation-ID');
            if (headerValue) return headerValue;
        }
        return null;
    }

    /** Add a reference ID to a message when the server supplied one. */
    buildUserMessage(message, correlationId) {
        return correlationId ? `${message} (Ref: ${correlationId})` : message;
    }

    /** Parse JSON safely. */
    safeParseJson(text) {
        if (!text) return null;
        try {
            return JSON.parse(text);
        } catch (error) {
            return null;
        }
    }

    /** Log development diagnostics unless logging is disabled or production is active. */
    logDev(entry) {
        if (this.global.APP_CONFIG.loggingEnabled === false) {
            return;
        }
        if ((this.global.APP_CONFIG.env || 'production') === 'production') {
            return;
        }
        // Avoid dumping large payloads or PII; callers should sanitize context.
        console.log('[app]', { ...entry });
    }

    /** Normalize a jQuery AJAX failure into an Error with its correlation ID. */
    handleAjaxFailure(jqXHR, endpoint, fallbackMessage = 'Request failed.') {
        const payload = jqXHR?.responseJSON ?? this.safeParseJson(jqXHR?.responseText);
        const correlationId = this.extractCorrelationId(payload, jqXHR);
        const message = payload?.error?.message ?? payload?.message ?? fallbackMessage;
        const errorCode =
            payload?.error?.code ??
            payload?.errorCode ??
            jqXHR?.statusText ??
            'request_failed';

        this.logDev({
            level: 'error',
            endpoint,
            status: jqXHR?.status,
            errorCode,
            correlationId,
            message,
        });

        const error = new Error(this.buildUserMessage(message, correlationId));
        error.correlationId = correlationId;
        error.errorCode = errorCode;
        return error;
    }
}

const appErrorHandler = new AppErrorHandler(window);
window.AppError = {
    extractCorrelationId: appErrorHandler.extractCorrelationId.bind(appErrorHandler),
    buildUserMessage: appErrorHandler.buildUserMessage.bind(appErrorHandler),
    logDev: appErrorHandler.logDev.bind(appErrorHandler),
    handleAjaxFailure: appErrorHandler.handleAjaxFailure.bind(appErrorHandler),
};
