"""Require the actual production pagination source before compiling local assets."""
import argparse
from pathlib import Path

def validate(source):
    source=Path(source)
    required=source/'vendor/laravel/framework/src/Illuminate/Pagination/resources/views/tailwind.blade.php'
    if not required.is_file():raise ValueError('Production Composer pagination templates must be present before Vite; frozen builds must not silently omit vendor utility styles')
    return required

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('source',type=Path);a=p.parse_args();validate(a.source);print('Production frontend template inputs verified')
