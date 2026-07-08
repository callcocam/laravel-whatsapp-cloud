<?php

namespace Callcocam\WhatsAppCloud\Exceptions;

/**
 * There is no usable channel for the requested tenant/context (no resolved
 * credentials). A terminal, expected state — jobs log and return instead of
 * retrying, and controllers surface it to the operator (who can configure a
 * number or switch to manual mode).
 */
class WhatsAppNotConfiguredException extends WhatsAppException {}
