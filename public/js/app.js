function copyId(btn, id) {
    navigator.clipboard?.writeText(id).then(() => {
        toast('تم نسخ ' + id);
    }).catch(() => {});
}

function toast(msg) {
    const t = document.getElementById('toast');
    if(t) {
        t.textContent = msg;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 2200);
    }
}

// Auto-advance OTP Inputs
document.querySelectorAll('.otp-inputs input').forEach((input, index, inputs) => {
    input.addEventListener('input', () => {
        if (input.value.length === 1 && index < inputs.length - 1) {
            inputs[index + 1].focus();
        }
    });
});
