from __future__ import annotations

from decimal import Decimal, InvalidOperation

from django import template

register = template.Library()


@register.filter
def int_it(value) -> str:
    try:
        return f"{int(value):,}".replace(",", ".")
    except (TypeError, ValueError):
        return "0"


@register.filter
def euro(value) -> str:
    try:
        number = Decimal(value or 0)
    except (InvalidOperation, TypeError, ValueError):
        number = Decimal("0")
    formatted = f"{number:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
    return f"€ {formatted}"


@register.filter
def duration_compact(value) -> str:
    try:
        total = max(0, int(value or 0))
    except (TypeError, ValueError):
        total = 0
    hours, remainder = divmod(total, 3600)
    minutes, seconds = divmod(remainder, 60)
    parts: list[str] = []
    if hours:
        parts.append(f"{hours} h")
    if minutes:
        parts.append(f"{minutes} min")
    if seconds and not hours:
        parts.append(f"{seconds} sec")
    return " ".join(parts) if parts else "0 min"


@register.filter
def minutes_remaining(value) -> str:
    try:
        minutes = max(0, int(value or 0))
    except (TypeError, ValueError):
        minutes = 0
    hours, rest = divmod(minutes, 60)
    if hours and rest:
        return f"{hours} h {rest} min"
    if hours:
        return f"{hours} h"
    return f"{rest} min"


@register.filter
def query_without(request, keys: str) -> str:
    params = request.GET.copy()
    for key in keys.split(","):
        params.pop(key.strip(), None)
    return params.urlencode()
