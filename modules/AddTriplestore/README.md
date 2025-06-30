# AddTriplestore Module for Omeka S

The **AddTriplestore** module allows you to upload, manage, and view archaeological excavation and artifact data in Omeka S, with support for triplestore (RDF) data integration.

## Features

- Upload and manage excavation and artifact data
- Convert data to RDF (Turtle/TTL) format
- GraphDB integration for RDF storage and validation
- Download RDF (TTL) representations
- Search and view archaeological datasets
- User authentication for data submission
- Form-based data entry for arrowheads and excavations
- XML to TTL conversion support

## Requirements

- Omeka S 4.0 or higher
- PHP 8.0 or higher
- GraphDB instance (for RDF storage and validation)
- **Collecting module** (modified version included)

## Installation

### 1. Install the AddTriplestore Module

Download or clone the AddTriplestore module into your Omeka S `modules` directory:

```sh
cd /path/to/omeka-s/modules
git clone <repository-url> AddTriplestore
```

### 2. Install/Update the Collecting Module

**Important**: This module requires a modified version of the Collecting module that includes additional functionality for archaeological data forms.

If you already have the Collecting module installed:

1. **Backup your existing Collecting module**:
   ```sh
   cp -r modules/Collecting modules/Collecting_backup
   ```

2. **Replace with the modified version**:
   ```sh
   rm -rf modules/Collecting
   cp -r AddTriplestore/modules/Collecting modules/
   ```

If you don't have the Collecting module installed:

```sh
cp -r AddTriplestore/modules/Collecting modules/
```

### 3. Configure GraphDB (Optional but Recommended)

1. **Install GraphDB** (if not already installed):
   - Download GraphDB from [Ontotext](https://www.ontotext.com/products/graphdb/)
   - Follow installation instructions for your platform

2. **Create GraphDB configuration** (optional):
   ```sh
   cp modules/AddTriplestore/config/graphdb.config.php.dist modules/AddTriplestore/config/graphdb.config.php
   ```

3. **Edit the configuration file**:
   ```php
   <?php
   return [
       'username' => 'your_graphdb_username',
       'password' => 'your_graphdb_password'
   ];
   ```

### 4. Install Modules in Omeka S

1. **Log into your Omeka S admin panel**
2. **Navigate to Modules**
3. **Install the Collecting module first**:
   - Find "Collecting" in the module list
   - Click "Install"
4. **Install the AddTriplestore module**:
   - Find "AddTriplestore" in the module list
   - Click "Install"

### 5. Configure Module Settings

1. **Navigate to your site's settings**
2. **Configure the AddTriplestore module**:
   - Set up GraphDB endpoints if using GraphDB
   - Configure user permissions for data submission

## Changes to the Collecting Module

The included Collecting module has been modified to support archaeological data collection:

### New Features Added:

1. **Archaeological Form Controllers**:
   - `uploadArrowheadFormAction()` - Handles arrowhead data forms
   - `uploadExcavationFormAction()` - Handles excavation data forms
   - Enhanced form processing for archaeological data

2. **Context Selection Features**:
   - Dynamic loading of squares, contexts, and SVUs from item sets
   - Relationship validation between archaeological entities
   - Helper methods for property checking and value extraction

3. **Enhanced Data Processing**:
   - Support for complex archaeological data relationships
   - Integration with AddTriplestore for RDF conversion
   - File upload handling for archaeological datasets

### Modified Files:

- `src/Controller/Site/IndexController.php` - Added archaeological form handlers
- Enhanced form processing capabilities
- Added methods for archaeological context management

## Usage

### Creating Archaeological Forms

1. **Navigate to your site's admin panel**
2. **Go to Collecting > Forms**
3. **Create forms for**:
   - Excavation data collection
   - Arrowhead/artifact data collection

### Uploading Data

1. **Access the site frontend**
2. **Navigate to the upload interface**
3. **Choose upload type**:
   - File upload (TTL, XML)
   - Form-based entry
4. **Select or create item sets** for organizing data

### Downloading Data

1. **Browse items or item sets**
2. **Use the download options** to get TTL representations
3. **Data is automatically organized** by resource type

## Configuration Files

### GraphDB Configuration

Create `modules/AddTriplestore/config/graphdb.config.php`:

```php
<?php
return [
    'username' => 'admin',
    'password' => 'admin'
];
```

### Template Files

The module includes template files for:
- Arrowhead data templates (`asset/templates/arrowhead.ttl`, `arrowhead.xml`)
- Excavation data templates (`asset/templates/excavation.ttl`, `excavation.xml`)

## API Endpoints

The module provides several routes for data management:

### Site Routes
- `/s/{site-slug}/add-triplestore/` - Main module interface
- `/s/{site-slug}/add-triplestore/login` - User authentication
- `/s/{site-slug}/add-triplestore/upload` - Data upload interface
- `/s/{site-slug}/add-triplestore/search` - Search interface
- `/s/{site-slug}/add-triplestore/download-ttl` - TTL download
- `/s/{site-slug}/add-triplestore/sparql` - SPARQL query interface

### Form Integration Routes
- `/s/{site-slug}/collecting/upload-arrowhead-form` - Arrowhead form interface
- `/s/{site-slug}/collecting/upload-excavation-form` - Excavation form interface

## Data Formats

### Supported Input Formats
- **TTL (Turtle)** - Native RDF format
- **XML** - Archaeological data in XML format (converted to TTL)

### Output Formats
- **TTL (Turtle)** - For download and GraphDB storage
- **HTML** - For web display

### Archaeological Data Structure

The module supports structured archaeological data including:

#### Excavation Data
- Site information (name, location, GPS coordinates)
- Archaeologist details (name, ORCID, email)
- Temporal data (excavation dates, chronological periods)
- Spatial data (squares, contexts, stratigraphic units)

#### Artifact Data (Arrowheads)
- Typological information (shape, variant, base type)
- Morphological data (point definition, body symmetry)
- Measurements (height, width, thickness, weight)
- Chipping analysis (mode, direction, amplitude)
- Contextual relationships (found in square/context/SVU)

## Troubleshooting

### Common Issues

1. **GraphDB Connection Issues**:
   - Verify GraphDB is running on `http://localhost:7200`
   - Check credentials in configuration file
   - Ensure network connectivity

2. **File Upload Issues**:
   - Check PHP file upload limits (`upload_max_filesize`, `post_max_size`)
   - Verify directory permissions for `/logs` and `/modules`
   - Ensure file formats are supported (TTL, XML)

3. **Module Dependencies**:
   - Ensure Collecting module is installed first
   - Verify all required PHP extensions are available
   - Check for conflicts with existing modules

4. **User Authentication Issues**:
   - Verify user roles and permissions
   - Check site-specific user settings
   - Ensure proper ACL configuration

### Logging

Check logs in:
- `logs/ttl_upload.log` - TTL processing logs
- `logs/graphdb-errors.log` - GraphDB interaction logs
- `logs/convert.log` - Data conversion logs
- `logs/graphdb-validation.log` - SHACL validation logs

### Debug Mode

Enable debug logging by checking the log files mentioned above. The module provides detailed logging for:
- Data conversion processes
- GraphDB interactions
- User authentication events
- Form processing

## Development

### File Structure

```
AddTriplestore/
├── asset/
│   ├── templates/          # Data templates
│   └── xlst/              # XSLT transformation files
├── config/
│   └── module.config.php  # Module configuration
├── src/
│   └── Controller/
│       └── Site/
│           ├── IndexController.php
│           └── IndexControllerFactory.php
└── modules/
    └── Collecting/        # Modified Collecting module
```

### Key Classes

- `AddTriplestore\Controller\Site\IndexController` - Main controller
- Authentication and user management
- Data conversion and validation
- GraphDB integration
- Form processing

### Extending the Module

To add new data types:

1. Create new form templates in `/asset/templates/`
2. Add conversion logic in the controller
3. Update the XSLT files for XML conversion
4. Add new routes in `module.config.php`

## Support

For issues and support:

1. Check the error logs mentioned above
2. Verify all installation steps were completed
3. Ensure all dependencies are properly installed
4. Check GraphDB connectivity and configuration

## Contributing

When contributing:

1. Follow PSR-4 autoloading standards
2. Add appropriate error handling and logging
3. Update documentation for new features
4. Test with both file upload and form-based data entry

## License

This module is released under the same license as Omeka S (GPL v3).

## Credits

- Built for archaeological data management and research
- Integrates with GraphDB for semantic data storage
- Extends the Omeka S Collecting module functionality
- Supports CIDOC-CRM and custom archaeological ontologies