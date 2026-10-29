"""Patch only document.xml in the retained Son Tien quotation DOCX.

Usage: python build-son-tien-quotation.py SOURCE.docx OUTPUT.docx
The two payment rows follow the structured payment choice on the quotation.
"""
import sys
import hashlib
from pathlib import Path
from zipfile import ZipFile
from lxml import etree as E

source, target = map(Path, sys.argv[1:3])
assert source.resolve() != target.resolve()
ns = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
w = '{'+ns['w']+'}'
original_hash = hashlib.sha256(source.read_bytes()).hexdigest()

def text(node):
    return ''.join(node.xpath('.//w:t/text()', namespaces=ns))

def put(node, value):
    # Preserve paragraph/run styling, cell properties and relationships.
    paragraphs = node.xpath('./w:p', namespaces=ns) if node.tag == w+'tc' else [node]
    p = paragraphs[0] if paragraphs else E.SubElement(node, w+'p')
    runs = p.xpath('./w:r', namespaces=ns)
    r = runs[0] if runs else E.SubElement(p, w+'r')
    for paragraph in paragraphs:
        for t in paragraph.xpath('.//w:t', namespaces=ns): t.text = ''
    ts = r.xpath('./w:t', namespaces=ns)
    t = ts[0] if ts else E.SubElement(r, w+'t')
    t.text = value
    t.set('{http://www.w3.org/XML/1998/namespace}space','preserve')

with ZipFile(source) as z:
    root = E.fromstring(z.read('word/document.xml'))
    body = root.find(w+'body')
    paragraphs = body.findall(w+'p')
    assert 'BG2026/ST-0807' in text(paragraphs[1]), 'Unexpected reference header'
    put(paragraphs[1], 'Số: {{ma_bao_gia}} | Ngày: {{ngay_bao_gia}}')
    transfer = next(p for p in paragraphs if 'Nội dung chuyển khoản:' in text(p))
    put(transfer, 'Nội dung chuyển khoản: {{ten_khach_hang}} thanh toan Bao gia {{ma_bao_gia}}')
    tables = body.findall(w+'tbl')
    assert len(tables) == 2
    parties, items = tables
    party_rows = parties.findall(w+'tr')
    for row, token in [(1,'{{ten_khach_hang}}'),(2,'{{dia_chi_cong_ty}}'),(3,'{{so_dien_thoai}}'),(4,'{{email}}'),(5,'{{nguoi_dai_dien_phap_luat}}')]:
        cells = party_rows[row].findall(w+'tc')
        assert len(cells)==4
        put(cells[3], token)
    rows = items.findall(w+'tr')
    assert len(rows)==9 and len(rows[1].findall(w+'tc'))==6
    # One real template row is repeated by the CRM merger for all quote items.
    for cell, token in zip(rows[1].findall(w+'tc'), ['stt','ten_hang','dvt','so_luong','don_gia','thanh_tien']):
        put(cell, '{{'+token+'}}')
    for row in rows[2:5]: items.remove(row)
    put(rows[5].findall(w+'tc')[-1], '{{tong_cong}}')
    put(rows[6].findall(w+'tc')[0], 'Bằng chữ: {{tong_tien_bang_chu}}')
    put(rows[7].findall(w+'tc')[0], '{{nhan_thanh_toan_dau}}')
    put(rows[7].findall(w+'tc')[-1], '{{gia_tri_thanh_toan_dau}}')
    put(rows[8].findall(w+'tc')[0], '{{nhan_thanh_toan_con_lai}}')
    put(rows[8].findall(w+'tc')[-1], '{{gia_tri_thanh_toan_con_lai}}')
    target.parent.mkdir(parents=True,exist_ok=True)
    with ZipFile(target,'w') as dest:
        for part in z.infolist():
            data = E.tostring(root,xml_declaration=True,encoding='UTF-8',standalone=True) if part.filename=='word/document.xml' else z.read(part.filename)
            dest.writestr(part, data)
with ZipFile(source) as a, ZipFile(target) as b:
    assert a.namelist()==b.namelist()
    assert all(a.read(n)==b.read(n) for n in a.namelist() if n!='word/document.xml')
    x=E.fromstring(a.read('word/document.xml'));y=E.fromstring(b.read('word/document.xml'))
    for expr in ['//w:sectPr','//w:tblPr','//w:tblGrid','//w:drawing']:
        assert [E.tostring(n) for n in x.xpath(expr,namespaces=ns)]==[E.tostring(n) for n in y.xpath(expr,namespaces=ns)]
assert original_hash==hashlib.sha256(source.read_bytes()).hexdigest()
print(target)
print('PASS preserved source, package parts, section geometry, table grids and drawings')
