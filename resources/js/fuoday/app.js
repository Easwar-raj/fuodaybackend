import angular from 'angular';
import './nav.js'; 

const app = angular.module('fuoday', []);

app.controller('FuodayController', function($scope) {
    $scope.message = "Running Correctly in Fuoday";
    $scope.head = "Title";
});

