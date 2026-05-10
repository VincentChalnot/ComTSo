# ComTSo Forum - Symfony 8

A modern forum application built with Symfony 8.0, FrankenPHP, Vue 3, and Bulma CSS.

Simple Forum with photo albums, private messages, global instant messaging, file sharing.

## Overview

ComTSo is a feature-rich forum platform recreated from scratch using the latest technologies:

- **Symfony 8.0** - Latest PHP framework with PHP 8.3+ attributes
- **FrankenPHP** - Modern PHP application server (Caddy module)
- **Vue 3** - Progressive JavaScript framework for interactive components
- **Vite** - Fast frontend build tool
- **Bulma CSS** - Modern CSS framework
- **Doctrine ORM** - Database abstraction with MariaDB
- **Mercure** - Real-time push notifications for chat

## Features

### Core Functionality
- **User Authentication** - Registration, login, remember me, profile management
- **Forum System** - Multiple forums with topics and threaded comments
- **Photo Gallery** - Photo uploads with EXIF data extraction and lightbox viewing
- **Private Messaging** - User-to-user private messages
- **Real-time Chat** - Global chat with Mercure push notifications
- **Theme Toggle** - Light/dark mode with user preference persistence

### Technical Features
- PHP 8 attributes for Doctrine ORM mapping
- Service layer for image processing and text formatting
- BBCode and Markdown text formatting
- EXIF data extraction from photos
- Responsive design with Bulma CSS
- Vue 3 components for interactive features
- Docker development environment

## Requirements

- Docker and Docker Compose
- Git

## Installation

### 1. Clone the repository

```bash
git clone <repository-url>
cd ComTSo
```

### 2. Build and start Docker containers

```bash
docker compose up -d
```

This will start:
- **php** - FrankenPHP container running Symfony
- **database** - MariaDB 11.4
- **node** - Node 20 for asset compilation
- **mercure** - Mercure hub for real-time features

### 3. Install dependencies

```bash
# Install PHP dependencies
docker compose exec php composer install

# Install Node dependencies
docker compose exec node npm install
```

### 4. Set up the database

```bash
# Create database
docker compose exec php bin/console doctrine:database:create

# Run migrations
docker compose exec php bin/console doctrine:migrations:migrate
```

### 5. Load fixtures (optional)

```bash
docker compose exec php bin/console doctrine:fixtures:load
```

### 6. Build assets

```bash
docker compose exec node npm run build
```

For development with hot module replacement:

```bash
docker compose exec node npm run dev
```

### 7. Access the application

Open your browser and navigate to:
- **Application**: https://localhost
- **Mercure Hub**: https://localhost/.well-known/mercure

## Project Structure

```
ComTSo/
├── assets/                   # Frontend assets
│   ├── styles/              # SCSS files
│   │   └── main.scss        # Main stylesheet with Bulma
│   ├── vue/                 # Vue 3 components
│   │   ├── components/      # Individual components
│   │   │   ├── ChatWidget.vue
│   │   │   ├── PhotoGallery.vue
│   │   │   ├── PhotoUploader.vue
│   │   │   └── ThemeToggle.vue
│   │   └── app.js           # Vue app initialization
│   └── app.js               # Main entry point
├── config/                  # Symfony configuration
│   ├── packages/            # Package configurations
│   └── services.yaml        # Service container config
├── migrations/              # Doctrine migrations
├── public/                  # Web root
│   ├── assets/              # Compiled assets
│   └── uploads/             # User uploads
│       └── photos/          # Photo uploads
├── src/
│   ├── Controller/          # Symfony controllers
│   │   ├── ChatController.php
│   │   ├── CommentController.php
│   │   ├── ForumController.php
│   │   ├── HomeController.php
│   │   ├── MessageController.php
│   │   ├── PhotoController.php
│   │   ├── SecurityController.php
│   │   ├── TopicController.php
│   │   └── UserController.php
│   ├── Entity/              # Doctrine entities
│   │   ├── ChatMessage.php
│   │   ├── Comment.php
│   │   ├── Forum.php
│   │   ├── Message.php
│   │   ├── Photo.php
│   │   ├── PhotoTopic.php
│   │   ├── Quote.php
│   │   ├── Topic.php
│   │   └── User.php
│   ├── Form/                # Symfony forms
│   │   ├── CommentType.php
│   │   ├── LoginFormType.php
│   │   ├── PhotoType.php
│   │   ├── RegistrationFormType.php
│   │   ├── TopicType.php
│   │   └── UserProfileType.php
│   ├── Repository/          # Doctrine repositories
│   ├── Service/             # Application services
│   │   ├── ImageProcessingService.php
│   │   └── TextFormattingService.php
│   └── Kernel.php
├── templates/               # Twig templates
│   ├── base.html.twig       # Base layout
│   ├── chat/
│   ├── comment/
│   ├── forum/
│   ├── home/
│   ├── message/
│   ├── photo/
│   ├── security/
│   ├── topic/
│   └── user/
├── compose.yaml             # Docker Compose configuration
├── Dockerfile               # Docker image definition
├── package.json             # Node dependencies
├── vite.config.js           # Vite configuration
└── composer.json            # PHP dependencies
```

## Key Components

### Entities

All entities use PHP 8 attributes for Doctrine mapping:

- **User** - Implements Symfony UserInterface, stores theme preferences
- **Forum** - Forum categories with string IDs (slugs)
- **Topic** - Discussion topics with view/comment tracking
- **Comment** - Threaded comments on topics
- **Photo** - Photo uploads with EXIF data
- **PhotoTopic** - Many-to-many relation for photos in topics
- **Message** - Private messages between users
- **ChatMessage** - Global chat messages
- **Quote** - Quote system for forums

### Vue Components

#### ThemeToggle
Toggles between light and dark themes, persists preference to user config.

```vue
<theme-toggle></theme-toggle>
```

#### PhotoUploader
Multi-file photo upload with preview and progress tracking.

```vue
<photo-uploader api-url="{{ path('photo_api_upload') }}"></photo-uploader>
```

#### PhotoGallery
Image grid with lightbox for full-size viewing and EXIF data display.

```vue
<photo-gallery :photos='{{ photos|json_encode|raw }}'></photo-gallery>
```

#### ChatWidget
Real-time chat with Mercure integration for live message updates.

```vue
<chat-widget
    :initial-messages='{{ messages|json_encode|raw }}'
    api-messages-url="{{ path('chat_api_messages') }}"
    api-send-url="{{ path('chat_api_send') }}"
    mercure-url="{{ mercure_public_url }}"
></chat-widget>
```

### Services

#### ImageProcessingService
- EXIF data extraction and formatting
- Image validation
- Dimension detection
- GPS coordinate extraction
- Thumbnail generation support

#### TextFormattingService
- BBCode formatting ([b], [i], [url], [quote], etc.)
- Markdown formatting
- Text sanitization
- Auto-linking URLs
- Spam detection
- Text excerpts

## Development

### Running tests

```bash
docker compose exec php bin/phpunit
```

### Code style

```bash
# PHP CS Fixer
docker compose exec php vendor/bin/php-cs-fixer fix

# PHPStan
docker compose exec php vendor/bin/phpstan analyse
```

### Database migrations

Create a new migration:

```bash
docker compose exec php bin/console make:migration
```

Execute migrations:

```bash
docker compose exec php bin/console doctrine:migrations:migrate
```

### Asset compilation

Development mode with HMR:

```bash
docker compose exec node npm run dev
```

Production build:

```bash
docker compose exec node npm run build
```

## Configuration

### Environment Variables

Key environment variables in `.env`:

```env
APP_ENV=dev
APP_SECRET=<your-secret>
DATABASE_URL=mysql://comtso:comtso@database:3306/comtso
MERCURE_URL=https://mercure/.well-known/mercure
MERCURE_PUBLIC_URL=https://localhost/.well-known/mercure
MERCURE_JWT_SECRET=<your-jwt-secret>
```

### Upload Directories

Configure upload paths in `config/services.yaml`:

```yaml
parameters:
    upload_directory: '%kernel.project_dir%/public/uploads'
    photos_directory: '%kernel.project_dir%/public/uploads/photos'
```

## Security Features

- Password hashing with Symfony's PasswordHasher
- CSRF protection on forms
- User roles and authorization (ROLE_USER, ROLE_ADMIN)
- Remember me functionality
- Logout on invalid sessions
- Image upload validation
- Text sanitization for XSS prevention

## Technology Stack

### Backend
- **PHP 8.3+** - Modern PHP with attributes and enums
- **Symfony 8.0** - Full-stack PHP framework
- **Doctrine ORM** - Database abstraction
- **MariaDB 11.4** - Relational database
- **FrankenPHP** - Application server
- **Mercure** - Real-time push notifications

### Frontend
- **Vue 3** - Progressive framework
- **Vite** - Build tool
- **Bulma CSS** - CSS framework
- **SCSS** - CSS preprocessor
- **Axios** - HTTP client

### Development
- **Docker** - Containerization
- **Docker Compose** - Multi-container orchestration
- **Git** - Version control

## Migration from Symfony 2.7

This is a complete rewrite of the original ComTSo application. Key changes:

1. **Symfony 2.7 → 8.0** - Complete framework upgrade
2. **Annotations → Attributes** - PHP 8 attributes instead of Doctrine annotations
3. **FOSUserBundle → Native Security** - Built-in Symfony Security component
4. **Assetic → Vite** - Modern asset pipeline
5. **Bootstrap → Bulma** - New CSS framework
6. **jQuery → Vue 3** - Modern reactive framework
7. **Theme System** - Simplified to light/dark toggle
8. **Real-time Chat** - Added Mercure for push notifications

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

[Add your license here]

## Support

For issues and questions, please use the GitHub issue tracker.
