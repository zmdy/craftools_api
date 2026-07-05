(function () {
    // "Selecionar todas" no cabeçalho da tabela de frases -- marca/desmarca
    // todas as checkboxes de linha (que enviam para #bulk-edit-form via o
    // atributo form=, mesmo estando fora da tag <form> na árvore do DOM).
    var selectAll = document.getElementById('ph-select-all');
    if (!selectAll) return;
    selectAll.addEventListener('change', function () {
        document.querySelectorAll('.ph-row-check').forEach(function (cb) {
            cb.checked = selectAll.checked;
        });
    });
})();
