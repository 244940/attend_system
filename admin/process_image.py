import sys
import json
import base64
import face_recognition
import numpy as np
from PIL import Image

def process_image(image_path):
    try:
        # Load the image
        image = face_recognition.load_image_file(image_path)
        
        # Find all face locations in the image
        face_locations = face_recognition.face_locations(image)
        
        # If no faces found, return error
        if len(face_locations) == 0:
            return {
                "status": "error",
                "message": "No faces detected in the image"
            }
        
        # If multiple faces found, return error
        if len(face_locations) > 1:
            return {
                "status": "error",
                "message": f"Multiple faces ({len(face_locations)}) detected in the image"
            }
        
        # Get face encodings
        face_encodings = face_recognition.face_encodings(image, face_locations)
        
        # Convert the numpy array to a list and then encode as base64
        encoding_bytes = face_encodings[0].tobytes()
        encoding_base64 = base64.b64encode(encoding_bytes).decode('utf-8')
        
        # Return success response
        return {
            "status": "success",
            "encoding": encoding_base64
        }
        
    except Exception as e:
        return {
            "status": "error",
            "message": str(e)
        }

if __name__ == "__main__":
    if len(sys.argv) != 2:
        result = {
            "status": "error",
            "message": "Usage: python process_image.py <image_path>"
        }
    else:
        result = process_image(sys.argv[1])
    
    # Print the JSON result
    print(json.dumps(result))