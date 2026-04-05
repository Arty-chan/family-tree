/* Photo preview – update the preview image when a new file is selected */
(function () {
    var input = document.getElementById('photo-input');
    if (!input) return;
    input.addEventListener('change', function () {
        var file = input.files[0];
        if (!file || !file.type.startsWith('image/')) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            var preview = document.getElementById('photo-preview');
            if (!preview) return;
            var img = document.getElementById('photo-preview-img');
            if (img) {
                img.src = e.target.result;
            } else {
                preview.innerHTML = '<img id="photo-preview-img" src="' + e.target.result + '" alt="New photo">';
            }
        };
        reader.readAsDataURL(file);
    });
}());
