'use strict';

document.addEventListener('DOMContentLoaded', function() {
    // Confirmation dialogs for forms with data-confirm attribute
    document.querySelectorAll('[data-confirm]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!confirm(form.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    // Spouse fields toggle (add_member.php & edit_member.php)
    var relType    = document.getElementById('rel_type');
    var spFields   = document.getElementById('spouse-fields');
    var personWrap = document.getElementById('rel-person-wrap');

    if (relType) {
        function updateRelFields() {
            var v = relType.value;
            if (personWrap) personWrap.style.display = v ? '' : 'none';
            if (spFields)   spFields.classList.toggle('hidden', v !== 'spouse');
        }
        relType.addEventListener('change', updateRelFields);
        updateRelFields();
    }

    // Spouse relationship inline edit toggle
    document.querySelectorAll('.rel-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var readView = btn.closest('.rel-years-read');
            var li = readView.closest('li');
            var relId = readView.dataset.rel;
            var form = li.querySelector('.rel-spouse-form[data-rel="' + relId + '"]');
            readView.classList.add('hidden');
            form.classList.remove('hidden');
        });
    });
    document.querySelectorAll('.rel-cancel-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var form = btn.closest('.rel-spouse-form');
            var li = form.closest('li');
            var relId = form.dataset.rel;
            var readView = li.querySelector('.rel-years-read[data-rel="' + relId + '"]');
            form.classList.add('hidden');
            readView.classList.remove('hidden');
        });
    });
});
