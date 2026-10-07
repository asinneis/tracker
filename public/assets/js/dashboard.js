// Сортировка матрицы по клику на заголовок столбца.
// Данные для сортировки берутся из атрибута data-sort каждой ячейки.
function sortMatrix(th) {
  const table = th.closest('table');
  const tbody = table.tBodies[0];
  const idx = Array.prototype.indexOf.call(th.parentNode.children, th);
  const type = th.dataset.type || 'num';
  const dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';

  // Сбрасываем индикаторы у других заголовков.
  table.querySelectorAll('th').forEach(function (h) {
    if (h !== th) { delete h.dataset.dir; h.classList.remove('is-asc', 'is-desc'); }
  });
  th.dataset.dir = dir;
  th.classList.remove('is-asc', 'is-desc');
  th.classList.add(dir === 'asc' ? 'is-asc' : 'is-desc');

  const rows = Array.prototype.slice.call(tbody.rows);
  rows.sort(function (a, b) {
    let va = a.cells[idx].dataset.sort || '';
    let vb = b.cells[idx].dataset.sort || '';
    let r;
    if (type === 'num') {
      r = parseFloat(va) - parseFloat(vb);
    } else {
      va = va.toLowerCase(); vb = vb.toLowerCase();
      r = va < vb ? -1 : (va > vb ? 1 : 0);
    }
    return dir === 'asc' ? r : -r;
  });
  rows.forEach(function (r) { tbody.appendChild(r); });
}
window.sortMatrix = sortMatrix;
