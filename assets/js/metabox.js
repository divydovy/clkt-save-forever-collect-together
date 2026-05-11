function toggleTimeline(element) {
    const content = element.nextElementSibling;
    const chevron = element.querySelector('.clkt-timeline-chevron');

    if (content.classList.contains('open')) {
        content.classList.remove('open');
        chevron.classList.remove('open');
    } else {
        content.classList.add('open');
        chevron.classList.add('open');
    }
}

function toggleSettings(element) {
    const content = element.nextElementSibling;
    const chevron = element.querySelector('.clkt-settings-chevron');

    if (content.style.display === 'none') {
        content.style.display = 'block';
        chevron.style.transform = 'rotate(180deg)';
    } else {
        content.style.display = 'none';
        chevron.style.transform = 'rotate(0deg)';
    }
}
