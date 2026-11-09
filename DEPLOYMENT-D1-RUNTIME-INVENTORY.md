# D1 runtime inventory appendix

Audit date: 2026-11-08 (Asia/Manila). Read with [the D1 plan](DEPLOYMENT-D1.md). No installation data or credentials are included.

## Evidence and table legend

- Current application requires PHP `^8.2`; `composer.lock` has 76 production packages. The Windows kit pins PHP 8.4.26 and CPython 3.12.10.
- The Python inventory below covers all **56** exact pins in `school-vehicle-monitoring-detector/requirements-windows.txt`; the general requirements file is a range-based development contract, not a platform lock.
- **W** means supplier version metadata contains a compatible CPython 3.12 wheel candidate, including pure-Python or older stable-ABI wheels. It does **not** mean that binary was executed, all DLL/dylib dependencies resolve, or OCR was tested on that OS. **—** means no such candidate was found.
- Windows targets `win_amd64`; Mac columns mean native `macosx` x86_64/arm64 or universal2, not Windows wheels or Rosetta. Wheel versions and availability were read from each linked PyPI version JSON, without downloading or installing them.
- Common offline method for every row: download vetted wheels on the build machine, record SHA-256 for every wheel, resolve and check dependencies, install into a clean target-native runtime, test imports/inference, and ship that runtime/site-packages plus wheel notices. No target-machine pip or package downloads. Pure-Python files can be common across architectures; native wheels and bundled libraries cannot.
- License labels summarize supplier metadata, not a completed redistribution review. Preserve each wheel’s license/NOTICE files and audit embedded native libraries separately. Exact metadata and matching filenames are retained in the private audit artifacts.

## Current Python lock

| Package / pinned version | Actual dependency role | Win x64 | Mac Intel | Mac arm64 | Redistribution attention |
| --- | --- | --- | --- | --- | --- |
| [certifi 2026.4.22](https://pypi.org/project/certifi/2026.4.22/) | HTTP CA bundle (not the installation CA) | W | W | W | MPL family; verify file notices |
| [charset-normalizer 3.4.7](https://pypi.org/project/charset-normalizer/3.4.7/) | HTTP text decoding | W | W | W | MIT family; verify bundled notices |
| [colorama 0.4.6](https://pypi.org/project/colorama/0.4.6/) | Windows terminal output | W | W | W | Verify wheel license/NOTICE metadata |
| [contourpy 1.3.3](https://pypi.org/project/contourpy/1.3.3/) | Plotting native dependency | W | W | W | BSD family; include bundled notices |
| [cycler 0.12.1](https://pypi.org/project/cycler/0.12.1/) | Plot styles | W | W | W | MPL family; verify file notices |
| [easyocr 1.7.2](https://pypi.org/project/easyocr/1.7.2/) | Offline fallback OCR | W | W | W | Apache family; verify bundled notices |
| [fast-plate-ocr 1.1.0](https://pypi.org/project/fast-plate-ocr/1.1.0/) | Primary cropped-plate ONNX OCR | W | W | W | MIT family; verify bundled notices |
| [filelock 3.29.0](https://pypi.org/project/filelock/3.29.0/) | Package/runtime file locking | W | W | W | MIT family; verify bundled notices |
| [flatbuffers 25.12.19](https://pypi.org/project/flatbuffers/25.12.19/) | ONNX binary support | W | W | W | Apache family; verify bundled notices |
| [fonttools 4.62.1](https://pypi.org/project/fonttools/4.62.1/) | Font parsing | W | W | W | MIT family; verify bundled notices |
| [fsspec 2026.3.0](https://pypi.org/project/fsspec/2026.3.0/) | Torch file abstraction | W | W | W | BSD family; include bundled notices |
| [idna 3.13](https://pypi.org/project/idna/3.13/) | HTTP hostnames | W | W | W | BSD family; include bundled notices |
| [imageio 2.37.3](https://pypi.org/project/imageio/2.37.3/) | Image I/O | W | W | W | BSD family; include bundled notices |
| [jinja2 3.1.6](https://pypi.org/project/jinja2/3.1.6/) | Torch/template dependency | W | W | W | Verify wheel license/NOTICE metadata |
| [kiwisolver 1.5.0](https://pypi.org/project/kiwisolver/1.5.0/) | Plot layout native solver | W | W | W | BSD family; include bundled notices |
| [lazy-loader 0.5](https://pypi.org/project/lazy-loader/0.5/) | Image-library lazy imports | W | W | W | BSD family; include bundled notices |
| [markdown-it-py 4.2.0](https://pypi.org/project/markdown-it-py/4.2.0/) | Rich output formatting | W | W | W | Verify wheel license/NOTICE metadata |
| [markupsafe 3.0.3](https://pypi.org/project/markupsafe/3.0.3/) | Template escaping | W | W | W | BSD family; include bundled notices |
| [matplotlib 3.10.9](https://pypi.org/project/matplotlib/3.10.9/) | Ultralytics plotting dependency | W | W | W | BSD family; include bundled notices |
| [mdurl 0.1.2](https://pypi.org/project/mdurl/0.1.2/) | Rich URL parsing | W | W | W | Verify wheel license/NOTICE metadata |
| [mpmath 1.3.0](https://pypi.org/project/mpmath/1.3.0/) | Symbolic arithmetic | W | W | W | BSD family; include bundled notices |
| [networkx 3.6.1](https://pypi.org/project/networkx/3.6.1/) | Torch graph dependency | W | W | W | BSD family; include bundled notices |
| [ninja 1.13.0](https://pypi.org/project/ninja/1.13.0/) | Native build helper dependency; not an installer prerequisite | W | W | W | Verify wheel license/NOTICE metadata |
| [numpy 2.4.3](https://pypi.org/project/numpy/2.4.3/) | Numeric image/tensor arrays | W | W | W | BSD family; include bundled notices |
| [onnxruntime 1.31.0](https://pypi.org/project/onnxruntime/1.31.0/) | CPU inference engine | W | — | W | MIT family; verify bundled notices |
| [opencv-python 4.13.0.92](https://pypi.org/project/opencv-python/4.13.0.92/) | Video capture, decoding, image processing | W | W | W | Apache family; verify bundled notices |
| [opencv-python-headless 4.13.0.92](https://pypi.org/project/opencv-python-headless/4.13.0.92/) | Overlapping cv2 provider required by OCR dependencies | W | W | W | Apache family; verify bundled notices |
| [packaging 26.2](https://pypi.org/project/packaging/26.2/) | Version/tag parsing | W | W | W | BSD family; include bundled notices |
| [pillow 12.2.0](https://pypi.org/project/pillow/12.2.0/) | Image codecs | W | W | W | MIT family; verify bundled notices |
| [polars 1.40.1](https://pypi.org/project/polars/1.40.1/) | Ultralytics data dependency | W | W | W | MPL family; verify file notices |
| [polars-runtime-32 1.40.1](https://pypi.org/project/polars-runtime-32/1.40.1/) | Polars native runtime | W | W | W | MIT family; verify bundled notices |
| [protobuf 7.36.2](https://pypi.org/project/protobuf/7.36.2/) | ONNX protocol support | W | W | W | BSD family; include bundled notices |
| [psutil 7.2.2](https://pypi.org/project/psutil/7.2.2/) | Network/process inspection | W | W | W | BSD family; include bundled notices |
| [pyclipper 1.4.0](https://pypi.org/project/pyclipper/1.4.0/) | OCR polygon geometry | W | W | W | MIT family; verify bundled notices |
| [pygments 2.21.0](https://pypi.org/project/pygments/2.21.0/) | Rich syntax coloring | W | W | W | BSD family; include bundled notices |
| [pyparsing 3.3.2](https://pypi.org/project/pyparsing/3.3.2/) | Plot/parser dependency | W | W | W | MIT family; verify bundled notices |
| [pytesseract 0.3.13](https://pypi.org/project/pytesseract/0.3.13/) | Optional Tesseract executable wrapper | W | W | W | Apache family; verify bundled notices |
| [python-bidi 0.6.7](https://pypi.org/project/python-bidi/0.6.7/) | OCR text processing | W | W | W | Verify wheel license/NOTICE metadata |
| [python-dateutil 2.9.0.post0](https://pypi.org/project/python-dateutil/2.9.0.post0/) | Date parsing | W | W | W | Verify wheel license/NOTICE metadata |
| [pyyaml 6.0.3](https://pypi.org/project/pyyaml/6.0.3/) | Model/tracker configuration | W | W | W | MIT family; verify bundled notices |
| [requests 2.33.1](https://pypi.org/project/requests/2.33.1/) | Laravel/device HTTP calls | W | W | W | Apache family; verify bundled notices |
| [rich 15.0.0](https://pypi.org/project/rich/15.0.0/) | Local diagnostic formatting | W | W | W | MIT family; verify bundled notices |
| [scikit-image 0.26.0](https://pypi.org/project/scikit-image/0.26.0/) | OCR image processing | W | W | W | BSD family; include bundled notices |
| [scipy 1.17.1](https://pypi.org/project/scipy/1.17.1/) | Scientific/native numerical functions | W | W | W | BSD family; include bundled notices |
| [setuptools 81.0.0](https://pypi.org/project/setuptools/81.0.0/) | Package compatibility support | W | W | W | MIT family; verify bundled notices |
| [shapely 2.1.2](https://pypi.org/project/shapely/2.1.2/) | OCR native geometry | W | W | W | BSD family; include bundled notices |
| [six 1.17.0](https://pypi.org/project/six/1.17.0/) | Compatibility dependency | W | W | W | MIT family; verify bundled notices |
| [sympy 1.14.0](https://pypi.org/project/sympy/1.14.0/) | Torch symbolic dependency | W | W | W | BSD family; include bundled notices |
| [tifffile 2026.5.2](https://pypi.org/project/tifffile/2026.5.2/) | Image format dependency | W | W | W | BSD family; include bundled notices |
| [torch 2.11.0](https://pypi.org/project/torch/2.11.0/) | YOLO/EasyOCR inference; optional CUDA/MPS detection | W | — | W | BSD family; include bundled notices |
| [torchvision 0.26.0](https://pypi.org/project/torchvision/0.26.0/) | YOLO/EasyOCR vision operators | W | — | W | BSD family; include bundled notices |
| [tqdm 4.70.1](https://pypi.org/project/tqdm/4.70.1/) | Progress output | W | W | W | MPL family; verify file notices |
| [typing-extensions 4.15.0](https://pypi.org/project/typing-extensions/4.15.0/) | Runtime typing compatibility | W | W | W | Python/PSF family; verify notices |
| [ultralytics 8.4.41](https://pypi.org/project/ultralytics/8.4.41/) | Vehicle/plate detection and tracking | W | W | W | AGPL-3.0; distribution decision required |
| [ultralytics-thop 2.0.19](https://pypi.org/project/ultralytics-thop/2.0.19/) | Model operation-count dependency | W | W | W | AGPL-3.0; distribution decision required |
| [urllib3 2.6.3](https://pypi.org/project/urllib3/2.6.3/) | HTTP connection layer | W | W | W | MIT family; verify bundled notices |

## Important native/platform qualifications

- Current Apple Silicon ONNX Runtime 1.31.0 wheels target macOS **14.0+**. OpenCV 4.13.0.92 targets **13.0+** for arm64 and **14.0+** for Intel; the latter cannot complete the current Intel environment because Torch, Torchvision and ONNX Runtime pins lack Intel wheels. This is wheel metadata, not certification of a complete macOS installation.
- The Intel investigation found CPython 3.12 candidates for Torch 2.2.2 / Torchvision 0.17.2 and ONNX Runtime 1.23.2 (13.0+) or 1.20.1 (universal2, 13.0+). An Intel candidate lock must also select compatible NumPy/OpenCV versions; OpenCV 4.10.0.84 Intel wheels target 12.0+ and allow NumPy 1.26.4. Test the complete graph and model behavior before accepting it. Do not simply downgrade three packages in the current directory.
- Both OpenCV distributions currently own `cv2`. Assembly order and native video backend must be made deterministic or the dependency-provider conflict resolved. Do not delete one requirement without checking consumers.
- `pytesseract` is not the Tesseract executable or English trained data. The current manifest does not supply either.
- Windows DLL/VC runtime prerequisites, macOS wheel deployment targets, CPU instruction requirements, Mach-O dependencies, signing and privacy permissions need artifact-level native checks. Python interpreter relocation is separate from wheel availability.

## Composer production dependency closure

These are the exact locked production PHP packages, not development tools. Common method: build `vendor/` from the reviewed lock with `--no-dev`, run `composer check-platform-reqs --no-dev` using each packaged PHP, retain license notices, and ship vendor files. PHP source portability is inferred; runtime support is conditional on the native PHP/extensions matrix in the main report. The table is not 76 independent native-OS certifications.

| Package | Locked version | Declared license |
| --- | --- | --- |
| brick/math | 0.14.8 | MIT |
| carbonphp/carbon-doctrine-types | 3.2.0 | MIT |
| dflydev/dot-access-data | v3.0.3 | MIT |
| doctrine/inflector | 2.1.0 | MIT |
| doctrine/lexer | 3.0.1 | MIT |
| dragonmantank/cron-expression | v3.6.0 | MIT |
| egulias/email-validator | 4.0.4 | MIT |
| fruitcake/php-cors | v1.4.0 | MIT |
| graham-campbell/result-type | v1.1.4 | MIT |
| guzzlehttp/guzzle | 7.10.0 | MIT |
| guzzlehttp/promises | 2.3.0 | MIT |
| guzzlehttp/psr7 | 2.8.0 | MIT |
| guzzlehttp/uri-template | v1.0.5 | MIT |
| laravel/framework | v12.53.0 | MIT |
| laravel/prompts | v0.3.13 | MIT |
| laravel/serializable-closure | v2.0.10 | MIT |
| laravel/tinker | v2.11.1 | MIT |
| league/commonmark | 2.8.1 | BSD-3-Clause |
| league/config | v1.2.0 | BSD-3-Clause |
| league/flysystem | 3.32.0 | MIT |
| league/flysystem-local | 3.31.0 | MIT |
| league/mime-type-detection | 1.16.0 | MIT |
| league/uri | 7.8.0 | MIT |
| league/uri-interfaces | 7.8.0 | MIT |
| monolog/monolog | 3.10.0 | MIT |
| nesbot/carbon | 3.11.1 | MIT |
| nette/schema | v1.3.5 | BSD-3-Clause, GPL-2.0-only, GPL-3.0-only |
| nette/utils | v4.1.3 | BSD-3-Clause, GPL-2.0-only, GPL-3.0-only |
| nikic/php-parser | v5.7.0 | BSD-3-Clause |
| nunomaduro/termwind | v2.4.0 | MIT |
| phpoption/phpoption | 1.9.5 | Apache-2.0 |
| psr/clock | 1.0.0 | MIT |
| psr/container | 2.0.2 | MIT |
| psr/event-dispatcher | 1.0.0 | MIT |
| psr/http-client | 1.0.3 | MIT |
| psr/http-factory | 1.1.0 | MIT |
| psr/http-message | 2.0 | MIT |
| psr/log | 3.0.2 | MIT |
| psr/simple-cache | 3.0.0 | MIT |
| psy/psysh | v0.12.21 | MIT |
| ralouphie/getallheaders | 3.0.3 | MIT |
| ramsey/collection | 2.1.1 | MIT |
| ramsey/uuid | 4.9.2 | MIT |
| symfony/clock | v8.0.0 | MIT |
| symfony/console | v7.4.7 | MIT |
| symfony/css-selector | v8.0.6 | MIT |
| symfony/deprecation-contracts | v3.6.0 | MIT |
| symfony/error-handler | v7.4.4 | MIT |
| symfony/event-dispatcher | v8.0.4 | MIT |
| symfony/event-dispatcher-contracts | v3.6.0 | MIT |
| symfony/finder | v7.4.6 | MIT |
| symfony/http-foundation | v7.4.7 | MIT |
| symfony/http-kernel | v7.4.7 | MIT |
| symfony/mailer | v7.4.6 | MIT |
| symfony/mime | v7.4.7 | MIT |
| symfony/polyfill-ctype | v1.33.0 | MIT |
| symfony/polyfill-intl-grapheme | v1.33.0 | MIT |
| symfony/polyfill-intl-idn | v1.33.0 | MIT |
| symfony/polyfill-intl-normalizer | v1.33.0 | MIT |
| symfony/polyfill-mbstring | v1.33.0 | MIT |
| symfony/polyfill-php80 | v1.33.0 | MIT |
| symfony/polyfill-php83 | v1.33.0 | MIT |
| symfony/polyfill-php84 | v1.33.0 | MIT |
| symfony/polyfill-php85 | v1.33.0 | MIT |
| symfony/polyfill-uuid | v1.33.0 | MIT |
| symfony/process | v7.4.5 | MIT |
| symfony/routing | v7.4.6 | MIT |
| symfony/service-contracts | v3.6.1 | MIT |
| symfony/string | v8.0.6 | MIT |
| symfony/translation | v8.0.6 | MIT |
| symfony/translation-contracts | v3.6.1 | MIT |
| symfony/uid | v7.4.4 | MIT |
| symfony/var-dumper | v7.4.6 | MIT |
| tijsverkoyen/css-to-inline-styles | v2.4.0 | BSD-3-Clause |
| vlucas/phpdotenv | v5.6.3 | BSD-3-Clause |
| voku/portable-ascii | 2.0.3 | MIT |

Build-only tools: Git; Composer 2; pinned Node/npm (installed Vite requires Node `^20.19.0 || >=22.12.0`); Python/pip capable of obtaining wheels; PowerShell 7 for the Windows builder; Inno Setup 6.3+; platform compilers/SDKs and signing/notarization tools for the Mac runtime; release checksum/signature tools. Windows installation uses built-in PowerShell 5.1; Mac installation uses supplied helpers and system tools. Browser clients require none of these development tools.
