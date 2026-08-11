# Gestion Scolaire

School management web application built with Laravel (REST API) and Vue 3 (SPA).

## Stack
- Backend: Laravel 13, MySQL
- Frontend: Vue 3 SPA (Vue Router, Axios)
- Local environment: Laragon

## Features
- CRUD management for classes, students, teachers, lessons
- Weekly timetable grid view
- REST API consumed by a decoupled Vue single-page application

## Setup
1. `composer install`
2. Copy `.env.example` to `.env` and configure the database
3. `php artisan migrate --seed`
4. `npm install && npm run build`
5. `php artisan serve`