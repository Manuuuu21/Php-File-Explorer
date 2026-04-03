# PHP File Explorer - Features Documentation

## Overview
A Material Design 3-based web file management system with cinematic UI, built with PHP backend and JavaScript frontend. It provides both secure admin access and shareable links for collaboration.

---

## Core Features

### 1. **Secure Login System**
- Robust session management with hardcoded credentials (admin/manuelsintos21)
- Persistent login state with session destroy on logout
- Material Design 3 styled login interface
- Error handling with user feedback

### 2. **File Explorer Interface**
- Displays files and folders in a data table format
- Multi-column sorting (Name, Date Modified, Type, Size)
- Breadcrumb navigation with mobile-responsive dropdown
- Sticky header with search and upload controls
- Context menu for quick actions

### 3. **Advanced Search**
- Recursive server-side search
- Real-time filtering with instant result updates
- Search results pagination (50 items per page)
- Works across entire directory structure
- Search path display in results

### 4. **Media Viewer (Cinematic UI)**
- **Supported Formats**:
  - **Images**: JPG, JPEG, PNG, GIF, WEBP, SVG
  - **Videos**: MP4, WEBM, OGG
  - **Audio**: MP3, WAV with animated vinyl disc (spinning animation)
  - **Documents**: PDF
  - **Code**: TXT, HTML, CSS, PHP, JS
  - **Spreadsheets**: XLS, XLSX

#### PDF Viewer with Slideshow
- Full-screen slideshow mode
- Page navigation with keyboard and mouse controls
- Auto-hiding controls with cursor management
- Responsive scaling to fit screen
- Keyboard shortcuts: Arrow keys, Space, Escape

#### Audio Player with Vinyl Animation
- Spinning vinyl record visual indicator
- Synchronized with playback (plays/pauses with audio)
- Material Design styled controls

#### Code Editor
- Powered by Ace.js with Monokai theme
- Syntax highlighting for multiple languages
- Read-only mode
- Monospace font with proper indentation

#### Excel/Spreadsheet Viewer
- Multi-sheet support with tab navigation
- Excel-like column headers (A, B, C...) and row numbers
- Resizable columns and rows
- Sheet switching functionality
- Powered by XLSX.js

### 5. **Advanced Upload Engine**
- **Features**:
  - Sequential file/folder uploads with drag-and-drop support
  - Real-time upload progress bars (percentage and bytes)
  - Upload speed indicator
  - Individual file abort capability
  - Batch processing with optimistic UI updates
  - Automatic storage limit validation (100GB)
  - Folder structure preservation
  - Handles multiple file selections

#### Upload Monitoring
- Upload counter with item count
- Individual file progress tracking
- Success/failure status for each file
- Session success count
- Persistent upload container

### 6. **Storage Management (100GB Quota)**
- **Features**:
  - Real-time disk usage tracking
  - Visual storage progress bar with color indicators
  - Storage limit enforcement (default 100GB, customizable)
  - Status colors:
    - Green: Normal (<75%)
    - Yellow: Warning (75-90%)
    - Red: Critical (>90%)
  - File breakdown by type (Video, Photo, Documents, Other)
  - Sorted file list by size (largest first)
  - Pagination for large file lists

#### Storage Breakdown Chart
- Stacked horizontal bar chart
- Color-coded segments:
  - Blue: Video files
  - Yellow: Photos
  - Red: Documents
  - Green: Other files
  - Gray: Free space

### 7. **Bulk Operations**
#### Bulk Delete
- Multi-select with checkboxes
- Confirmation dialog before deletion
- Optimistic UI with loading indicators
- Real-time file count display

#### Bulk Move
- Move multiple items to target directory
- Directory tree selection modal
- Prevents moving folder into itself
- Preserves folder structure

#### Bulk Copy
- Duplicate multiple items
- Auto-naming for copies (e.g., "Copy of filename")
- Incremental naming for multiple copies
- Preserves original structure

#### Bulk ZIP Download
- Compress multiple files/folders into single ZIP
- Server-side ZipArchive implementation
- Preserves directory structure
- Automatic cleanup of temporary files

### 8. **Shared Views & Public Links**
#### Share Link Generation
- Create secure read-only public access links
- Token-based sharing (32-character hex tokens)
- Share individual files or entire folders
- Optional upload permissions for shared folders
- Prevents re-creation of existing shares (caching)

#### Share Permissions
- Read-only by default
- Optional allow-upload for folders
- Upload/Create/Delete restrictions controlled by token
- Single-file share restrictions

### 9. **File Operations**
#### Create Folder
- Input validation and sanitization
- Duplicate prevention
- Real-time UI feedback
- Optimistic updates

#### Delete Files/Folders
- Single or bulk deletion
- Recursive folder deletion
- Confirmation dialogs
- Safe path validation

#### Rename Files
- Real-time renaming with validation
- Preserves file type/extension
- Error handling for duplicates

#### Move Files
- Modal-based destination selector
- Folder tree navigation
- Root folder option
- Prevent self-nesting

### 10. **Clipboard Operations (Copy/Cut/Paste)**
- **Keyboard Support**:
  - CTRL+C: Copy selected items
  - CTRL+X: Cut selected items
  - CTRL+V: Paste clipboard contents
  - CTRL+A: Select all items

#### Visual Feedback
- Cut items display with reduced opacity
- Clipboard status in snackbar notifications
- Distinction between copy and paste operations

### 11. **Performance Optimization**
#### Server-Side Caching
- Global file index caching with 5-minute TTL
- Recursive directory indexing
- Efficient pagination (100,000 items per page for browsing)

#### Memory Management
- 1024MB memory limit for large operations
- Unlimited execution time for long tasks
- Efficient use of RecursiveIterator

#### Client-Side Optimization
- Full index local filtering (when available)
- Pagination for search results
- Lazy loading of media files
- Optimistic UI updates

### 12. **Responsive Design**
- **Material Design 3 Components**: Modern M3 styling
- **Mobile Responsive**:
  - Collapsible sidebar
  - Mobile breadcrumb dropdown menu
  - Touch-friendly buttons and controls
  - Responsive table layout
  - Mobile-specific bulk action menus

#### Desktop Features
- Full sidebar with storage info
- Extended breadcrumb display
- Keyboard shortcuts
- Context menu support

#### Mobile Features
- Hamburger menu for sidebar
- Dropdown breadcrumb navigation
- Simplified action menus
- Touch-optimized interface

### 13. **Security Features**
#### Path Validation
- `safePath()` function prevents directory traversal
- Real path normalization
- Base directory confinement
- File access validation before operations

#### Session Management
- Login requirement for admin
- Public share links don't require login
- Separate admin and shared view states
- Logout functionality

#### Input Sanitization
- HTML escaping in JavaScript (`escapeHtml()`)
- SQL-style path escaping (`escapeJs()`)
- Filename validation for folder creation
- POST/GET parameter validation

### 14. **User Interface Features**
#### Keyboard Navigation
- Arrow keys for media navigation (in viewer)
- Escape to close modals
- Enter for search submission
- Ctrl+A, C, X, V for clipboard operations

### 15. **Statistics & Monitoring**
#### Real-Time Stats
- Total storage used and percentage
- Total file count in storage
- Used space with unit formatting
- Storage limit display


### 16. **File Download Support**
- Individual file downloads
- Forced download vs. inline display
- HTTP range support for partial downloads
- MIME type detection
- Works with shared links
- Browser-native download handling

---

## Technical Stack
| Component | Technology |
|-----------|------------|
| Backend | PHP 7.4+ |
| Frontend | Vanilla JavaScript (ES6+) |
| Styling | Custom CSS with Material Design 3 |
| Libraries | Ace.js (Code Editor), PDF.js (PDF Viewer), XLSX.js (Spreadsheet) |
| Server | Apache/Nginx with PHP support |
| Storage | File system based (100GB default) |


# Screenshots
## 1.
![Img](https://github.com/Manuuuu21/Php-File-Explorer/blob/main/{01E8EBBC-604F-4C96-AE87-11A481C864E8}.png)
## 2.
![Img](https://github.com/Manuuuu21/Php-File-Explorer/blob/main/{95603AA9-ABCC-45E1-B915-655FAD842B76}.png)
