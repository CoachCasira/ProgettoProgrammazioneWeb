from __future__ import annotations

from collections.abc import Iterable, Iterator
from html import escape
from typing import Any

from django.http import StreamingHttpResponse


def _cell(value: Any, kind: str, align: str) -> str:
    style = {
        ("text", "left"): "TextLeft",
        ("text", "right"): "TextRight",
        ("integer", "right"): "IntegerRight",
        ("number", "right"): "NumberRight",
        ("currency", "right"): "CurrencyRight",
        ("date", "right"): "DateRight",
    }.get((kind, align), "TextLeft")
    if value is None or value == "":
        return f'<Cell ss:StyleID="{style}"><Data ss:Type="String"></Data></Cell>'
    if kind in {"integer", "number", "currency"}:
        data_type = "Number"
        raw = str(value).replace(",", ".")
    else:
        data_type = "String"
        raw = escape(str(value))
    return f'<Cell ss:StyleID="{style}"><Data ss:Type="{data_type}">{raw}</Data></Cell>'


def spreadsheet_response(
    filename: str,
    sheet_name: str,
    headers: list[str],
    rows: Iterable[Iterable[Any]],
    aligns: list[str],
    kinds: list[str],
    note: str | None = None,
) -> StreamingHttpResponse:
    def generate() -> Iterator[bytes]:
        yield b'<?xml version="1.0" encoding="UTF-8"?>\n'
        yield b'<?mso-application progid="Excel.Sheet"?>\n'
        yield (
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            'xmlns:o="urn:schemas-microsoft-com:office:office" '
            'xmlns:x="urn:schemas-microsoft-com:office:excel" '
            'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            '<Styles>'
            '<Style ss:ID="Default"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="11"/></Style>'
            '<Style ss:ID="Header"><Alignment ss:Horizontal="Center"/><Font ss:Bold="1"/><Interior ss:Color="#A59990" ss:Pattern="Solid"/></Style>'
            '<Style ss:ID="TextLeft"><Alignment ss:Horizontal="Left"/></Style>'
            '<Style ss:ID="TextRight"><Alignment ss:Horizontal="Right"/></Style>'
            '<Style ss:ID="IntegerRight"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="0"/></Style>'
            '<Style ss:ID="NumberRight"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="0.00"/></Style>'
            '<Style ss:ID="CurrencyRight"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="€ #,##0.00"/></Style>'
            '<Style ss:ID="DateRight"><Alignment ss:Horizontal="Right"/></Style>'
            '<Style ss:ID="Note"><Font ss:Italic="1" ss:Color="#7D6D62"/></Style>'
            '</Styles>'
            f'<Worksheet ss:Name="{escape(sheet_name[:31])}"><Table>'
        ).encode("utf-8")
        if note:
            yield (
                f'<Row><Cell ss:StyleID="Note" ss:MergeAcross="{max(0, len(headers)-1)}">'
                f'<Data ss:Type="String">{escape(note)}</Data></Cell></Row>'
            ).encode("utf-8")
        yield ("<Row>" + "".join(
            f'<Cell ss:StyleID="Header"><Data ss:Type="String">{escape(header)}</Data></Cell>'
            for header in headers
        ) + "</Row>").encode("utf-8")
        for row in rows:
            cells = "".join(
                _cell(value, kinds[index], aligns[index])
                for index, value in enumerate(row)
            )
            yield f"<Row>{cells}</Row>".encode("utf-8")
        yield b"</Table></Worksheet></Workbook>"

    response = StreamingHttpResponse(generate(), content_type="application/vnd.ms-excel; charset=utf-8")
    response["Content-Disposition"] = f'attachment; filename="{filename}"'
    response["X-Content-Type-Options"] = "nosniff"
    return response
