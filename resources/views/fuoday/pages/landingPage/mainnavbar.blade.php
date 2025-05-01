<div ng-controller="NavbarController">
  <nav class="navbar bottomline">
    <!-- Logo -->
    <div class="nav-left">
      <div class="logo">
        <a href="#"><img src="{{ asset('images/office_logo.png') }}" alt="Logo"></a>
      </div>

      <!-- Navbar Items -->
      <ul class="nav-items">
        <li><a href="">Home</a></li>
        <li><a href="">HRMS</a></li>
        <li><a href="">ATS</a></li>
        <li><a href="">Payroll</a></li>
        {{-- <li><a href="">Expense</a></li>
        <li><a href="">Mail</a></li>
        <li><a href="">Projects</a></li> --}}
        <li class="dropdown">
          <a href="" class="dropdown-toggle" ng-click="toggleDropdown()">All Products</a>
          {{-- <ul class="dropdown-menu" ng-show="isDropdownOpen">
            <li><a href="">Option 1</a></li>
            <li><a href="">Option 2</a></li>
            <li><a href="">Option 3</a></li>
            <li><a href="">Option 4</a></li>
          </ul> --}}
        </li>
      </ul>
    </div>

    <!-- Right side -->
    <div class="nav-right">
      <!-- Search Bar -->
      <div class="search-bar">
        <input type="text" placeholder="Search..." ng-model="searchQuery">
        <button ng-click="search()"><img src="{{ asset('images/search_icons.svg') }}" alt=""></button>
      </div>

      <!-- Language Dropdown -->
      {{-- <div class="language-dropdown">
        <img src="{{ asset('images/globe_icons.png') }}" alt="">
        <select ng-model="selectedLanguage" ng-change="changeLanguage()">
          <option value="en">English</option>
          <option value="es">Español</option>
          <option value="fr">Français</option>
        </select>
      </div> --}}

      <!-- Login Section -->
      <div class="loginBlock">
        {{-- <div class="login">
            <a href="">Log in</a>
        </div> --}}
        {{-- <div class="profile">
            <img src="{{ asset('images/office_logo.png') }}" alt="">
        </div> --}}
      </div>
    </div>
  </nav>
</div>

