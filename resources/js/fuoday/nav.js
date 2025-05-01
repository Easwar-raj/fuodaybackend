

app.controller('NavbarController', function ($scope) {
    $scope.isMenuOpen = false;

    $scope.toggleMenu = function () {
      $scope.isMenuOpen = !$scope.isMenuOpen;
    };

    $scope.searchQuery = '';
    $scope.selectedLanguage = 'en';

    $scope.search = function () {
      console.log('Search query:', $scope.searchQuery);
      // Add search functionality here.
    };

    $scope.changeLanguage = function () {
      console.log('Selected language:', $scope.selectedLanguage);
      // Add language change functionality here.
    };
  });
  app.controller('NavbarController', function ($scope) {
    $scope.isDropdownOpen = false;

    $scope.toggleDropdown = function () {
      $scope.isDropdownOpen = !$scope.isDropdownOpen;
    };
  });

// Ensure dropdown toggles work on small screens
document.addEventListener("DOMContentLoaded", function () {
    const dropdowns = document.querySelectorAll('.dropdown-toggle');
    dropdowns.forEach(function (dropdown) {
      dropdown.addEventListener('click', function (e) {
        const menu = this.nextElementSibling;
        menu.classList.toggle('show');
      });
    });
  });