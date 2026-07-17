```mermaid
erDiagram

    MEDIA_ASSETS {
        bigint id PK
        string filename
        string mime_type
    }

    MEDIA_GALLERIES {
        bigint id PK
        string slug
        string title
    }

    MEDIA_GALLERY_ITEMS {
        bigint id PK
        bigint gallery_id FK
        bigint asset_id FK
    }

    PAGE_MEDIA_ASSIGNMENTS {
        bigint id PK
        bigint asset_id FK
        bigint gallery_id FK
        string page_key
        string slot_key
    }

    REFERENCED_PROJECTS {
        int id PK
        string slug
        string title
    }

    MEDIA_GALLERIES ||--o{ MEDIA_GALLERY_ITEMS : contains

    MEDIA_ASSETS ||--o{ MEDIA_GALLERY_ITEMS : assigned

    MEDIA_ASSETS ||--o{ PAGE_MEDIA_ASSIGNMENTS : used

    MEDIA_GALLERIES ||--o{ PAGE_MEDIA_ASSIGNMENTS : embedded
```