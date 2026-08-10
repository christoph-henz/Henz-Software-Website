(() => {
  const videos = Array.from(document.querySelectorAll('video[data-deferred-video="1"]'));

  if (videos.length === 0) {
    return;
  }

  const hydrateVideo = (video) => {
    if (video.dataset.videoHydrated === '1') {
      return;
    }

    const source = document.createElement('source');
    source.src = video.dataset.videoSrc || '';
    source.type = video.dataset.videoType || 'video/mp4';
    video.appendChild(source);
    video.dataset.videoHydrated = '1';
    video.load();

    const playPromise = video.play();
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch(() => {});
    }
  };

  if (!('IntersectionObserver' in window)) {
    videos.forEach(hydrateVideo);
    return;
  }

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) {
        return;
      }

      const video = entry.target;
      hydrateVideo(video);
      observer.unobserve(video);
    });
  }, {
    rootMargin: '250px 0px',
    threshold: 0.15,
  });

  videos.forEach((video) => observer.observe(video));
})();
