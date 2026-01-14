      </div><!-- .content-wrapper -->
      
      <div style="height:36px"></div>
    </div><!-- .main -->

  </div><!-- .app -->

  <script>
    /* -------------------------
       Mobile responsive menu
       ------------------------- */
    const sidebar = document.getElementById('sidebar');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');

    // Mobile menu toggle
    if (mobileMenuBtn) {
      mobileMenuBtn.addEventListener('click', () => {
        sidebar.classList.toggle('open');
      });
    }

    /* -------------------------
       Mobile responsive check
       ------------------------- */
    function checkMobile() {
      if (window.innerWidth <= 780) {
        mobileMenuBtn.style.display = 'block';
      } else {
        mobileMenuBtn.style.display = 'none';
        sidebar.classList.remove('open');
      }
    }

    // Check on load and resize
    checkMobile();
    window.addEventListener('resize', checkMobile);

    /* -------------------------
       Close mobile menu when clicking outside
       ------------------------- */
    document.addEventListener('click', (e) => {
      if (window.innerWidth <= 780 && 
          !sidebar.contains(e.target) && 
          !mobileMenuBtn.contains(e.target) &&
          sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
      }
    });
  </script>
</body>
</html>